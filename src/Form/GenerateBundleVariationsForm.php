<?php

namespace Drupal\commerce_variation_bundle\Form;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\commerce_variation_bundle\BundleVariationGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationTypeInterface;
use Drupal\commerce_product\ProductAttributeFieldManagerInterface;
use Drupal\commerce_product\ProductVariationStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;
use Drupal\commerce_variation_bundle\Entity\BundleItemTypeInterface;
use Drupal\Core\Url;

/**
 * Generates bundle variations from combinations of other products' variations.
 *
 * Products are added one at a time, and adding one loads its enabled variations
 * so that unwanted ones can be excluded before generating: the number of bundle
 * variations created is the product of the per-product selections, which grows
 * fast. Each product also carries a quantity, so the same variations can be
 * bundled more than once in different amounts. Field values entered on the form
 * are copied to every generated variation.
 */
final class GenerateBundleVariationsForm extends FormBase {

  /**
   * The id of the element wrapping the source product selection.
   */
  private const SOURCES_WRAPPER = 'bundle-sources-wrapper';

  /**
   * The id of the element wrapping the combination count.
   */
  private const SUMMARY_WRAPPER = 'bundle-summary-wrapper';

  /**
   * Refuse to generate more bundle variations than this in one submission.
   */
  private const COMBINATION_LIMIT = 500;

  /**
   * Constructs a new GenerateBundleVariationsForm object.
   *
   * The promoted properties are neither private nor readonly, because
   * DependencySerializationTrait - which FormBase uses on our behalf - restores
   * services after the form object is unserialized, and its __wakeup() can
   * reach neither a private property nor, before PHP 8.4, a readonly one
   * declared in a child class.
   *
   * @see https://www.drupal.org/node/3110266
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CurrentRouteMatch $currentRouteMatch,
    protected ProductAttributeFieldManagerInterface $attributeFieldManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected BundleVariationGeneratorInterface $variationGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('commerce_product.attribute_field_manager'),
      $container->get('module_handler'),
      $container->get('commerce_variation_bundle.variation_generator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_variation_bundle_generate_bundle_variations';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $product = $this->currentRouteMatch->getParameter('commerce_product');
    $variation_type = $this->getBundleVariationType($product);

    if (!$variation_type) {
      $form['error'] = [
        '#markup' => $this->t('This product does not use a bundle variation type.'),
      ];
      return $form;
    }

    $form_state->set('variation_type_id', $variation_type->id());
    $form_state->set('product_id', $product->id());
    if ($form_state->get('source_product_ids') === NULL) {
      $form_state->set('source_product_ids', []);
    }

    $form['sources'] = $this->buildSources($form_state);
    $form['sku_options'] = $this->buildSkuOptions($product);
    $form['title_options'] = $this->buildTitleOptions($variation_type);

    // Build a temporary (unsaved) variation to drive the field widgets.
    $variation = $this->entityTypeManager
      ->getStorage('commerce_product_variation')
      ->create(['type' => $variation_type->id()]);
    $form_state->set('variation', $variation);

    $form_display = EntityFormDisplay::collectRenderDisplay($variation, 'default');

    // Strip fields that are either set programmatically or must remain unique.
    $excluded = array_merge(
      ['bundle_items', 'sku', 'product_id', 'uid', 'created', 'changed', 'default_langcode'],
      array_keys($this->attributeFieldManager->getFieldMap($variation_type->id())),
    );
    if ($variation_type->shouldGenerateTitle()) {
      $excluded[] = 'title';
    }
    foreach ($excluded as $field_name) {
      $form_display->removeComponent($field_name);
    }
    $form_state->set('form_display', $form_display);

    // Build variation field widgets directly into $form, mirroring
    // ContentEntityForm so the layout matches the create variation form.
    $form_display->buildForm($variation, $form, $form_state);

    // The generated title replaces whatever is entered here, so hiding the
    // field says so plainly rather than leaving a value that is silently
    // ignored.
    if (isset($form['title'], $form['title_options'])) {
      $form['title']['#states']['invisible'] = [
        ':input[name="title_options[generate]"]' => ['checked' => TRUE],
      ];

      // #states is presentation only, and the title widget is required. Core
      // validates required elements before any #validate handler runs, so a
      // hidden and empty title would fail the form before validateForm() had a
      // chance to exclude it. The raw input is read rather than the values,
      // which are not mapped yet while the form is being built.
      if (!empty($form_state->getUserInput()['title_options']['generate'])) {
        $this->clearRequired($form['title']);
      }
    }

    // Attach field_group groups so tabs/fieldsets from the form display render.
    if ($this->moduleHandler->moduleExists('field_group')) {
      $context = [
        'entity_type' => $variation->getEntityTypeId(),
        'bundle' => $variation->bundle(),
        'entity' => $variation,
        'context' => 'form',
        'display_context' => 'form',
        'mode' => $form_display->getMode(),
      ];
      /* @phpstan-ignore-next-line */
      field_group_attach_groups($form, $context);
      $form['#process'][] = ['\Drupal\field_group\FormatterHelper', 'formProcess'];
      $form['#pre_render'][] = ['\Drupal\field_group\FormatterHelper', 'formGroupPreRender'];
    }

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate bundle variations'),
      '#button_type' => 'primary',
      // Marks the only button that runs the full validation - see
      // validateForm(), which the add, remove and ajax triggers must not run.
      '#generate' => TRUE,
    ];

    return $form;
  }

  /**
   * Builds the source product selection.
   *
   * @return array
   *   The sources render element.
   */
  private function buildSources(FormStateInterface $form_state): array {
    $sources = [
      '#type' => 'fieldset',
      '#title' => $this->t('Products to combine'),
      '#tree' => TRUE,
      '#weight' => -10,
      '#attributes' => ['id' => self::SOURCES_WRAPPER],
    ];

    $sources['add'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'commerce_product',
      '#title' => $this->t('Add a product'),
      '#description' => $this->t('One bundle variation is created per combination of the variations selected below.'),
      // Keeps bundle products out of the suggestions.
      '#selection_settings' => [
        'target_bundles' => $this->variationGenerator->getSourceProductTypeIds(),
      ],
      // The suggestions are filtered, but the reference is not validated
      // against that filter: a product typed in full would otherwise be
      // refused with "The referenced entity does not exist", which is both
      // untrue and unhelpful. addProduct() does the check instead and can say
      // why - it is a bundle, or it is this very product.
      '#validate_reference' => FALSE,
    ];
    $sources['add_submit'] = [
      '#type' => 'submit',
      '#name' => 'add_source_product',
      '#value' => $this->t('Add product'),
      '#submit' => ['::addProduct'],
      // Adding a product must not trip the validation of the variation fields
      // further down, which are not filled in yet.
      '#limit_validation_errors' => [['sources', 'add']],
      '#ajax' => [
        'callback' => '::refreshSources',
        'wrapper' => self::SOURCES_WRAPPER,
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Loading variations...'),
        ],
      ],
    ];

    $products = $this->getSourceProducts($form_state);
    if (!$products) {
      $sources['empty'] = [
        '#type' => 'item',
        '#markup' => $this->t('No products added yet. Add a product to choose which of its variations take part in the combinations.'),
        '#weight' => 10,
      ];

      return $sources;
    }

    $sources['products'] = ['#weight' => 10];
    foreach ($products as $product_id => $product) {
      $variations = $this->variationStorage()->loadEnabled($product);

      $sources['products'][$product_id] = [
        '#type' => 'details',
        '#open' => TRUE,
        '#title' => $this->formatPlural(
          count($variations),
          '@title (1 variation)',
          '@title (@count variations)',
          ['@title' => $product->label()],
        ),
      ];

      if ($variations) {
        $options = [];
        foreach ($variations as $variation_id => $variation) {
          $options[$variation_id] = $variation->getSku() . ' - ' . $variation->label();
        }

        $sources['products'][$product_id]['variations'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Variations to combine'),
          '#description' => $this->t('Clear the variations that should not take part. Every remaining variation is combined with every remaining variation of the other products.'),
          '#options' => $options,
          '#default_value' => array_keys($options),
          '#ajax' => [
            'callback' => '::refreshSummary',
            'wrapper' => self::SUMMARY_WRAPPER,
            'event' => 'change',
            'progress' => ['type' => 'none'],
          ],
          '#limit_validation_errors' => [['sources']],
        ];

        $sources['products'][$product_id]['quantity'] = [
          '#type' => 'number',
          '#title' => $this->t('Quantity per bundle'),
          '#description' => $this->t("How many units of this product's variation each generated bundle contains."),
          '#min' => 1,
          '#step' => 1,
          '#default_value' => 1,
          '#required' => TRUE,
        ];
      }
      else {
        $sources['products'][$product_id]['none'] = [
          '#type' => 'item',
          '#markup' => $this->t('This product has no enabled variations.'),
        ];
      }

      $sources['products'][$product_id]['remove'] = [
        '#type' => 'submit',
        '#name' => 'remove_source_product_' . $product_id,
        '#value' => $this->t('Remove product'),
        '#submit' => ['::removeProduct'],
        '#limit_validation_errors' => [],
        '#product_id' => $product_id,
        '#ajax' => [
          'callback' => '::refreshSources',
          'wrapper' => self::SOURCES_WRAPPER,
        ],
      ];
    }

    $sources['summary'] = $this->buildSummary($form_state);

    return $sources;
  }

  /**
   * Builds the SKU generation options.
   *
   * @return array
   *   The sku_options render element.
   */
  private function buildSkuOptions(ProductInterface $product): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('SKU generation'),
      '#tree' => TRUE,
      '#weight' => -9,
      '#open' => FALSE,
      'include_quantities' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Include the quantities'),
        '#description' => $this->t('Appends each quantity above one to the SKU it belongs to, so that %with is generated instead of %without. Enable this to bundle the same variations again in different amounts - without it the second run would produce an identical SKU and be skipped as a duplicate.', [
          '%with' => 'F381M-F382M-F1061x3',
          '%without' => 'F381M-F382M-F1061',
        ]),
        '#default_value' => TRUE,
      ],
      'include_product_id' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Include the parent product ID'),
        '#description' => $this->t('Prefixes the SKU with the ID of this product, giving %example, so that the same combination can be generated for more than one bundle product.', [
          '%example' => $product->id() . '-F381M-F382M-F1061x3',
        ]),
        '#default_value' => TRUE,
      ],
    ];
  }

  /**
   * Clears #required throughout a form subtree.
   *
   * @param array $element
   *   The element to walk, by reference.
   */
  private function clearRequired(array &$element): void {
    if (isset($element['#required'])) {
      $element['#required'] = FALSE;
    }
    foreach (Element::children($element) as $key) {
      $this->clearRequired($element[$key]);
    }
  }

  /**
   * Builds the title generation options.
   *
   * The format itself is not editable here: it belongs to the variation type
   * and the bundle item type, which is where it is configured and where it has
   * to live for VariationBundle::generateTitle() to keep applying it every
   * time a bundle is saved. This only chooses whether to name the generated
   * variations that way at all, and shows what the result will look like.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationTypeInterface $variation_type
   *   The variation type the bundles are created as.
   *
   * @return array
   *   The details element.
   */
  private function buildTitleOptions(ProductVariationTypeInterface $variation_type): array {
    $separator = $variation_type->getThirdPartySetting('commerce_variation_bundle', 'title_separator') ?? VariationBundleInterface::DEFAULT_TITLE_SEPARATOR;
    $example = $this->t('1x First item@separator2x Second item', ['@separator' => $separator]);

    $element = [
      '#type' => 'details',
      '#tree' => TRUE,
      '#title' => $this->t('Title generation'),
      '#weight' => -9,
      '#open' => FALSE,
    ];

    if ($variation_type->shouldGenerateTitle()) {
      $element['#description'] = $this->t('This variation type generates its own titles, so every bundle is named after the items it holds: %example. The format cannot be overridden here - it is reapplied every time a bundle is saved. Change it on the <a href=":url">variation type</a>.', [
        '%example' => $example,
        ':url' => $variation_type->toUrl('edit-form')->toString(),
      ]);

      return $element;
    }

    $element['#description'] = $this->t('Names each generated variation after the items it holds: %example.', [
      '%example' => $example,
    ]);
    $element['generate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generate the title from the bundle contents'),
      '#description' => $this->t('Leave this off to give every generated variation the title entered below.'),
      '#default_value' => FALSE,
    ];

    // Overridable only here. This variation type does not generate titles, so
    // what is written now is what the variations keep - nothing recomputes it
    // on a later save. The defaults come from the variation type and the
    // bundle item type, so leaving them alone matches the rest of the site.
    $visible = [
      '#states' => ['visible' => [':input[name="title_options[generate]"]' => ['checked' => TRUE]]],
    ];

    $element['item_pattern'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Item pattern'),
      '#description' => $this->t('How one item is named. Use @quantity and @title. Applies to this run only; the <a href=":url">bundle item type</a> holds the default.', [
        '@quantity' => '@quantity',
        '@title' => '@title',
        ':url' => Url::fromRoute('entity.commerce_bundle_item_type.collection')->toString(),
      ]),
      '#default_value' => $this->itemTitlePattern(),
      '#size' => 24,
    ] + $visible;

    $element['separator'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Separator'),
      '#description' => $this->t('Placed between the items. Applies to this run only; the <a href=":url">variation type</a> holds the default.', [
        ':url' => $variation_type->toUrl('edit-form')->toString(),
      ]),
      '#default_value' => $separator,
      '#size' => 8,
    ] + $visible;

    return $element;
  }

  /**
   * The default bundle item title pattern.
   *
   * @return string
   *   The pattern configured on the bundle item type the generator creates
   *   items as.
   */
  private function itemTitlePattern(): string {
    /** @var \Drupal\commerce_variation_bundle\Entity\BundleItemTypeInterface|null $type */
    $type = $this->entityTypeManager
      ->getStorage('commerce_bundle_item_type')
      ->load('default');

    return $type?->getTitlePattern() ?: BundleItemTypeInterface::DEFAULT_TITLE_PATTERN;
  }

  /**
   * Builds the line reporting how many bundle variations would be generated.
   *
   * @return array
   *   The summary render element.
   */
  private function buildSummary(FormStateInterface $form_state): array {
    $total = $this->countCombinations($form_state, $this->getSourceSelection($form_state));

    return [
      '#type' => 'container',
      '#weight' => 20,
      '#attributes' => ['id' => self::SUMMARY_WRAPPER],
      'text' => [
        '#type' => 'item',
        '#markup' => $total === 0
          ? $this->t('No combinations can be generated from the current selection.')
          : $this->formatPlural(
            $total,
            'The current selection generates <strong>1</strong> bundle variation.',
            'The current selection generates <strong>@count</strong> bundle variations.',
        ),
      ],
    ];
  }

  /**
   * Ajax callback returning the source product selection.
   */
  public function refreshSources(array $form, FormStateInterface $form_state): array {
    return $form['sources'];
  }

  /**
   * Ajax callback returning the combination count.
   */
  public function refreshSummary(array $form, FormStateInterface $form_state): array {
    // The summary only exists while products are added, which is also the only
    // time the checkboxes that trigger this callback exist.
    return $form['sources']['summary'] ?? [];
  }

  /**
   * Submit handler for the "Add product" button.
   */
  public function addProduct(array &$form, FormStateInterface $form_state): void {
    $product_id = $form_state->getValue(['sources', 'add']);
    $product_ids = $form_state->get('source_product_ids');

    /** @var \Drupal\commerce_product\Entity\ProductInterface|null $source */
    $source = $product_id
      ? $this->entityTypeManager->getStorage('commerce_product')->load($product_id)
      : NULL;

    if ($product_id && (string) $product_id === (string) $form_state->get('product_id')) {
      $this->messenger()->addWarning($this->t('A bundle product cannot be combined with itself.'));
    }
    elseif ($source && $this->getBundleVariationType($source)) {
      // A bundle of bundles multiplies out the inner combinations as well, and
      // the split, pricing and stock handling all assume a bundle item points
      // at a plain variation.
      $this->messenger()->addWarning($this->t('@label is a bundle product and cannot be used as a source.', [
        '@label' => $source->label(),
      ]));
    }
    elseif ($product_id && !in_array($product_id, $product_ids)) {
      $product_ids[] = $product_id;
      $form_state->set('source_product_ids', $product_ids);
    }

    // Clear the autocomplete so the next product can be typed straight away.
    $input = $form_state->getUserInput();
    unset($input['sources']['add']);
    $form_state->setUserInput($input);
    $form_state->setValue(['sources', 'add'], NULL);

    $form_state->setRebuild();
  }

  /**
   * Submit handler for the per-product "Remove product" buttons.
   */
  public function removeProduct(array &$form, FormStateInterface $form_state): void {
    $product_id = $form_state->getTriggeringElement()['#product_id'];
    $product_ids = array_values(array_diff($form_state->get('source_product_ids'), [$product_id]));
    $form_state->set('source_product_ids', $product_ids);

    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Only the generate button validates. The add, remove and ajax triggers
    // rebuild the form, and a validation error would stop that rebuild - which
    // is exactly what the user needs to see.
    if (empty($form_state->getTriggeringElement()['#generate'])) {
      return;
    }

    $products = $this->getSourceProducts($form_state);
    if (!$products) {
      $form_state->setErrorByName('sources][add', $this->t('Add at least one product to combine.'));
      return;
    }

    // Extract submitted values into the entity first so that entity-level
    // constraints (e.g. NotNull on price) have actual values when validated.
    $variation = $form_state->get('variation');
    $form_display = $form_state->get('form_display');

    // A generated title is built per variation, so the one on the template is
    // never used. Dropping the component keeps its required constraint from
    // failing a form whose title field is hidden, and keeps submitForm() from
    // copying it onto the generated variations.
    if (!empty($form_state->getValue(['title_options', 'generate']))) {
      $form_display->removeComponent('title');
    }

    $form_display->extractFormValues($variation, $form, $form_state);
    $form_display->validateFormValues($variation, $form, $form_state);

    foreach ($products as $product_id => $product) {
      if (!$this->variationStorage()->loadEnabled($product)) {
        $form_state->setErrorByName("sources][products][$product_id", $this->t('%title has no enabled variations. Remove it to continue.', [
          '%title' => $product->label(),
        ]));
      }
      elseif (!array_filter($form_state->getValue(['sources', 'products', $product_id, 'variations'], []))) {
        $form_state->setErrorByName("sources][products][$product_id][variations", $this->t('Select at least one variation of %title, or remove the product.', [
          '%title' => $product->label(),
        ]));
      }
    }

    $total = $this->countCombinations($form_state, $this->getSourceSelection($form_state));
    if ($total > self::COMBINATION_LIMIT) {
      $form_state->setErrorByName('sources', $this->t('The current selection would generate @total bundle variations, more than the limit of @limit. Clear some variations and generate the rest in a second run.', [
        '@total' => $total,
        '@limit' => self::COMBINATION_LIMIT,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $variation = $form_state->get('variation');
    $form_display = $form_state->get('form_display');
    $variation_type_id = $form_state->get('variation_type_id');
    $sku_options = $form_state->getValue('sku_options');
    $title_options = $form_state->getValue('title_options') ?? [];

    // Pull submitted field values into the template variation entity.
    $form_display->extractFormValues($variation, $form, $form_state);

    $product = $this->entityTypeManager
      ->getStorage('commerce_product')
      ->load($form_state->get('product_id'));

    $selection = $this->getSourceSelection($form_state);
    // The raw count, deliberately: an empty selection is the user's problem to
    // fix, while a subscriber emptying it is reported further down.
    if (!$this->variationGenerator->buildCombinations($selection)) {
      $this->messenger()->addError($this->t('No enabled variations found for the selected products.'));
      return;
    }

    $result = $this->variationGenerator->generate($product, $selection, [
      'variation_type' => $variation_type_id,
      'template' => $variation,
      'fields' => array_keys($form_display->getComponents()),
      'sku' => $sku_options,
      'title' => $title_options,
    ]);

    $created = $result->getCreatedCount();
    if ($created === 0 && $result->getDuplicateCount() === 0 && $result->getSkippedCount() === 0) {
      $this->messenger()->addWarning($this->t('No bundle variations were generated: every combination was filtered out.'));
    }
    if ($created > 0) {
      $this->messenger()->addMessage(
        $this->formatPlural($created, 'Generated 1 bundle variation.', 'Generated @count bundle variations.')
      );
    }

    $duplicates = $result->getDuplicateCount();
    if ($duplicates > 0) {
      $message = empty($sku_options['include_quantities'])
        ? $this->formatPlural($duplicates, 'Skipped 1 variation with a duplicate SKU. Enable "Include the quantities" under SKU generation to bundle the same variations again in different amounts.', 'Skipped @count variations with duplicate SKUs. Enable "Include the quantities" under SKU generation to bundle the same variations again in different amounts.')
        : $this->formatPlural($duplicates, 'Skipped 1 variation with a duplicate SKU.', 'Skipped @count variations with duplicate SKUs.');
      $this->messenger()->addWarning($message);
    }

    // Combinations a subscriber removed are not reported: it made a decision
    // on the site's behalf and does not need telling. Only the ones it built
    // and then skipped are, since those look like they should have appeared.
    $skipped = $result->getSkippedCount();
    if ($skipped > 0) {
      $this->messenger()->addWarning(
        $this->formatPlural($skipped, 'Skipped 1 variation.', 'Skipped @count variations.')
      );
    }

    $form_state->setRedirect('entity.commerce_product_variation.collection', [
      'commerce_product' => $product->id(),
    ]);
  }

  /**
   * Returns the products added to the form.
   *
   * The ids live in form state rather than being read back out of the
   * autocomplete, because buildForm() runs before submitted input is mapped
   * onto form values: on a plain submit there would be nothing to read.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface[]
   *   The products, keyed by id.
   */
  private function getSourceProducts(FormStateInterface $form_state): array {
    $product_ids = $form_state->get('source_product_ids');
    if (!$product_ids) {
      return [];
    }

    return $this->entityTypeManager
      ->getStorage('commerce_product')
      ->loadMultiple($product_ids);
  }

  /**
   * Returns the variations and quantity chosen for each added product.
   *
   * @return array[]
   *   Keyed by product id, each entry having a "variations" array keyed by
   *   variation id and an integer "quantity". Products whose selection is
   *   empty are left out.
   */
  private function getSourceSelection(FormStateInterface $form_state): array {
    $selection = [];

    foreach ($this->getSourceProducts($form_state) as $product_id => $product) {
      $enabled = $this->variationStorage()->loadEnabled($product);
      $submitted = $this->getSourceValue($form_state, $product_id, 'variations');

      // A missing value means the element has not been built yet, in which case
      // every variation counts, matching the checkboxes' default value.
      $selection[$product_id] = [
        'variations' => $submitted === NULL
          ? $enabled
          // Only ever trust ids that belong to this product's enabled
          // variations.
          : array_intersect_key($enabled, array_flip(array_filter((array) $submitted))),
        'quantity' => (int) ($this->getSourceValue($form_state, $product_id, 'quantity') ?: 1),
      ];
    }

    return $selection;
  }

  /**
   * Reads one per-product value, falling back to the raw input.
   *
   * Form values are unavailable in two places: buildForm() runs before
   * submitted input is mapped onto them, and #limit_validation_errors prunes
   * everything it leaves out. The raw input is there in both cases.
   *
   * @return mixed
   *   The value, or NULL when the element has not been submitted.
   */
  private function getSourceValue(FormStateInterface $form_state, string|int $product_id, string $key): mixed {
    return $form_state->getValue(['sources', 'products', $product_id, $key])
      ?? $form_state->getUserInput()['sources']['products'][$product_id][$key]
      ?? NULL;
  }

  /**
   * Returns how many combinations a selection produces.
   */
  private function countCombinations(FormStateInterface $form_state, array $selection): int {
    $product = $this->entityTypeManager
      ->getStorage('commerce_product')
      ->load($form_state->get('product_id'));

    if (!$product instanceof ProductInterface) {
      return 0;
    }

    // The count subscribers leave behind, not the raw arithmetic: the summary
    // would otherwise promise variations that are never created, and the limit
    // below would refuse a run a subscriber had already cut to size.
    return count($this->variationGenerator->getCombinations($product, $selection, [
      'variation_type' => $form_state->get('variation_type_id'),
    ]));
  }

  /**
   * Returns the product variation storage.
   */
  private function variationStorage(): ProductVariationStorageInterface {
    /** @var \Drupal\commerce_product\ProductVariationStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    return $storage;
  }

  /**
   * Returns the first variation type on the product that has the bundle trait.
   */
  private function getBundleVariationType($product): ?ProductVariationTypeInterface {
    /** @var \Drupal\commerce_product\Entity\ProductTypeInterface $product_type */
    $product_type = $this->entityTypeManager
      ->getStorage('commerce_product_type')
      ->load($product->bundle());

    if (!$product_type) {
      return NULL;
    }

    foreach ($product_type->getVariationTypeIds() as $variation_type_id) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationTypeInterface $variation_type */
      $variation_type = $this->entityTypeManager
        ->getStorage('commerce_product_variation_type')
        ->load($variation_type_id);

      if ($variation_type && $variation_type->hasTrait('purchasable_entity_variation_bundle')) {
        return $variation_type;
      }
    }

    return NULL;
  }

}
