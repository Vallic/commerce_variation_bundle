<?php

namespace Drupal\commerce_variation_bundle\Form;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\commerce_product\Entity\ProductVariationTypeInterface;
use Drupal\commerce_product\ProductAttributeFieldManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates all variation combinations for a bundle product.
 *
 * For each combination of variations across the selected products, the form
 * creates one ProductVariation of the bundle type with bundle_items pointing
 * to each variation in the combination. Field values entered on the form are
 * copied to every generated variation.
 */
final class GenerateBundleVariationsForm extends FormBase {

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

    $form['products'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'commerce_product',
      '#title' => $this->t('Products'),
      '#description' => $this->t('Select the products whose variations will be combined. One bundle variation is created per combination across all selected products.'),
      '#required' => TRUE,
      '#tags' => TRUE,
      '#weight' => -10,
    ];

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
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $products_value = $form_state->getValue('products') ?? [];

    if (empty($products_value)) {
      $form_state->setErrorByName('products', $this->t('Please select at least one product.'));
      return;
    }

    // Extract submitted values into the entity first so that entity-level
    // constraints (e.g. NotNull on price) have actual values when validated.
    $variation = $form_state->get('variation');
    $form_display = $form_state->get('form_display');
    $form_display->extractFormValues($variation, $form, $form_state);
    $form_display->validateFormValues($variation, $form, $form_state);

    /** @var \Drupal\commerce_product\ProductVariationStorageInterface $variation_storage */
    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');

    foreach (array_column($products_value, 'target_id') as $product_id) {
      $selected_product = $this->entityTypeManager->getStorage('commerce_product')->load($product_id);
      if (!$selected_product) {
        continue;
      }
      $variations = $variation_storage->loadEnabled($selected_product);
      if (empty($variations)) {
        $form_state->setErrorByName('products', $this->t('%title has no enabled variations.', [
          '%title' => $selected_product->label(),
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $variation = $form_state->get('variation');
    $form_display = $form_state->get('form_display');
    $product_id = $form_state->get('product_id');
    $variation_type_id = $form_state->get('variation_type_id');

    // Pull submitted field values into the template variation entity.
    $form_display->extractFormValues($variation, $form, $form_state);

    $product = $this->entityTypeManager->getStorage('commerce_product')->load($product_id);

    /** @var \Drupal\commerce_product\ProductVariationStorageInterface $variation_storage */
    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $bundle_item_storage = $this->entityTypeManager->getStorage('commerce_bundle_item');

    // Collect enabled variations for each selected product.
    $variation_sets = [];
    foreach (array_column($form_state->getValue('products'), 'target_id') as $pid) {
      $selected_product = $this->entityTypeManager->getStorage('commerce_product')->load($pid);
      if (!$selected_product) {
        continue;
      }
      $variations = array_values($variation_storage->loadEnabled($selected_product));
      if (!empty($variations)) {
        $variation_sets[] = $variations;
      }
    }

    if (empty($variation_sets)) {
      $this->messenger()->addError($this->t('No enabled variations found for the selected products.'));
      return;
    }

    $field_names = array_keys($form_display->getComponents());
    $combinations = $this->cartesianProduct($variation_sets);
    $created = 0;
    $skipped = 0;

    /** @var \Drupal\commerce_product\Entity\ProductVariationTypeInterface $variation_type */
    $variation_type = $this->entityTypeManager->getStorage('commerce_product_variation_type')->load($variation_type_id);
    $auto_title = $variation_type && $variation_type->shouldGenerateTitle();

    foreach ($combinations as $combo) {
      // Build a deterministic SKU from the constituent variation SKUs.
      $sku = substr(implode('-', array_map(fn($v) => $v->getSku(), $combo)), 0, 255);

      if ($variation_storage->loadBySku($sku)) {
        $skipped++;
        continue;
      }

      // Create a BundleItem entity for every variation in this combination.
      $bundle_items = [];
      foreach ($combo as $source_variation) {
        $bundle_item = $bundle_item_storage->create([
          'bundle' => 'default',
          'variation' => $source_variation->id(),
          'quantity' => 1,
          'status' => 1,
        ]);
        $bundle_item->save();
        $bundle_items[] = ['target_id' => $bundle_item->id()];
      }

      // Create the bundle variation and copy template field values.
      $new_variation = $variation_storage->create(['type' => $variation_type_id]);
      foreach ($field_names as $field_name) {
        if ($field_name === 'title' && $auto_title) {
          continue;
        }
        if ($new_variation->hasField($field_name)) {
          $new_variation->set($field_name, $variation->get($field_name)->getValue());
        }
      }
      $new_variation->set('sku', $sku);
      $new_variation->set('bundle_items', $bundle_items);
      $new_variation->save();

      $product->addVariation($new_variation);
      $created++;
    }

    $product->save();

    if ($created > 0) {
      $this->messenger()->addMessage(
        $this->formatPlural($created, 'Generated 1 bundle variation.', 'Generated @count bundle variations.')
      );
    }
    if ($skipped > 0) {
      $this->messenger()->addWarning(
        $this->formatPlural($skipped, 'Skipped 1 variation with a duplicate SKU.', 'Skipped @count variations with duplicate SKUs.')
      );
    }

    $form_state->setRedirect('entity.commerce_product_variation.collection', [
      'commerce_product' => $product->id(),
    ]);
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

  /**
   * Returns the cartesian product of an array of arrays.
   *
   * @param array[] $arrays
   *   Each inner array is a set of values for one dimension.
   *
   * @return array[]
   *   Every possible combination, one element per inner array.
   */
  private function cartesianProduct(array $arrays): array {
    $result = [[]];
    foreach ($arrays as $array) {
      $new_result = [];
      foreach ($result as $current) {
        foreach ($array as $item) {
          $new_result[] = array_merge($current, [$item]);
        }
      }
      $result = $new_result;
    }
    return $result;
  }

}
