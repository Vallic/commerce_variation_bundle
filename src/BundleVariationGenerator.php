<?php

namespace Drupal\commerce_variation_bundle;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_product\Entity\ProductVariationTypeInterface;
use Drupal\commerce_product\ProductVariationStorageInterface;
use Drupal\commerce_variation_bundle\Event\GenerateVariationEvent;
use Drupal\commerce_variation_bundle\Event\GenerateVariationsEvent;
use Drupal\commerce_variation_bundle\Event\VariationBundleEvents;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Drupal\commerce_variation_bundle\Entity\BundleItemTypeInterface;
use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;

/**
 * Default bundle variation generator.
 */
class BundleVariationGenerator implements BundleVariationGeneratorInterface {

  /**
   * Constructs the generator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads the storages the variations and bundle items are created through.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   Dispatches the two generation events.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function buildCombinations(array $selection): array {
    if (!$selection) {
      return [];
    }

    // One entry per variation, each carrying its product's quantity, so that
    // a combination knows how many units of each variation it holds.
    $dimensions = array_map(
      fn(array $source) => array_map(
        fn(ProductVariationInterface $variation) => [
          'variation' => $variation,
          'quantity' => (int) ($source['quantity'] ?? 1),
        ],
        array_values($source['variations'] ?? []),
      ),
      $selection,
    );

    // A product contributing no variations makes the whole product empty,
    // which is right: a combination draws one variation from every product.
    foreach ($dimensions as $dimension) {
      if (!$dimension) {
        return [];
      }
    }

    $combinations = [[]];
    foreach ($dimensions as $dimension) {
      $next = [];
      foreach ($combinations as $current) {
        foreach ($dimension as $item) {
          $next[] = array_merge($current, [$item]);
        }
      }
      $combinations = $next;
    }

    return $combinations;
  }

  /**
   * {@inheritdoc}
   */
  public function getCombinations(ProductInterface $product, array $selection, array $options = []): array {
    $variation_type_id = $options['variation_type'] ?? $this->getBundleVariationTypeId($product) ?? '';

    // Everything a subscriber needs to decide which combinations are worth
    // creating, before a single entity is built.
    $event = new GenerateVariationsEvent($product, $this->buildCombinations($selection), $selection, $variation_type_id);
    $this->eventDispatcher->dispatch($event, VariationBundleEvents::GENERATE_VARIATIONS);

    return $event->getCombinations();
  }

  /**
   * {@inheritdoc}
   */
  public function generate(ProductInterface $product, array $selection, array $options = []): BundleVariationGenerationResult {
    $variation_type_id = $options['variation_type'] ?? $this->getBundleVariationTypeId($product);
    if (!$variation_type_id) {
      throw new \InvalidArgumentException(sprintf('No bundle variation type given, and none could be derived from product type "%s".', $product->bundle()));
    }

    $combinations = $this->getCombinations($product, $selection, ['variation_type' => $variation_type_id]);

    if (!$combinations) {
      return new BundleVariationGenerationResult();
    }

    $variation_storage = $this->variationStorage();
    $bundle_item_storage = $this->entityTypeManager->getStorage('commerce_bundle_item');

    $template = $options['template'] ?? NULL;
    $field_names = $options['fields'] ?? ($template ? $this->getCopyableFields($template) : []);
    $sku_options = $options['sku'] ?? [];

    /** @var \Drupal\commerce_product\Entity\ProductVariationTypeInterface|null $variation_type */
    $variation_type = $this->entityTypeManager
      ->getStorage('commerce_product_variation_type')
      ->load($variation_type_id);
    $auto_title = $variation_type && $variation_type->shouldGenerateTitle();

    $created = [];
    $duplicates = 0;
    $skipped = 0;

    foreach ($combinations as $combination) {
      $sku = $this->buildSku($combination, $sku_options, $product);

      if ($variation_storage->loadBySku($sku)) {
        $duplicates++;
        continue;
      }

      // Built but not saved: a subscriber may still skip this combination, and
      // an unsaved bundle item leaves nothing to clean up.
      $bundle_items = [];
      foreach ($combination as $item) {
        $bundle_items[] = $bundle_item_storage->create([
          'bundle' => 'default',
          'variation' => $item['variation']->id(),
          'quantity' => $item['quantity'],
          'status' => 1,
        ]);
      }

      $variation = $variation_storage->create(['type' => $variation_type_id]);
      if ($template) {
        foreach ($field_names as $field_name) {
          // A generated title is recomputed on save; copying the template's
          // would freeze it.
          if ($field_name === 'title' && $auto_title) {
            continue;
          }
          if ($variation->hasField($field_name) && $template->hasField($field_name)) {
            $variation->set($field_name, $template->get($field_name)->getValue());
          }
        }
      }
      $variation->set('sku', $sku);

      // A variation type that generates titles from attributes recomputes the
      // title on save, so setting one here would only be overwritten.
      if (!$auto_title && !empty($options['title']['generate'])) {
        $variation->setTitle($this->buildTitle($combination, $options['title'] + [
          'variation_type' => $variation_type_id,
        ]));
      }

      $variation_event = new GenerateVariationEvent($variation, $bundle_items, $combination, $product);
      $this->eventDispatcher->dispatch($variation_event, VariationBundleEvents::GENERATE_VARIATION);
      if ($variation_event->isSkipped()) {
        $skipped++;
        continue;
      }

      $references = [];
      foreach ($variation_event->getBundleItems() as $bundle_item) {
        $bundle_item->save();
        $references[] = ['target_id' => $bundle_item->id()];
      }

      $variation = $variation_event->getVariation();
      $variation->set('bundle_items', $references);
      $variation->save();

      $product->addVariation($variation);
      $created[] = $variation;
    }

    if ($created) {
      $product->save();
    }

    return new BundleVariationGenerationResult($created, $duplicates, $skipped);
  }

  /**
   * Builds the title of one generated bundle variation.
   *
   * Reads the labels of the variations the bundle holds, so the title says
   * what is in the box - "UFO 3 - Black + 4 x Make My Day mask" rather than a
   * SKU nobody can read.
   *
   * @param array[] $combination
   *   The combination, each element having a "variation" and a "quantity".
   * @param array $title_options
   *   The title options, as described in the interface.
   *
   * @return string
   *   The title, truncated to the 255 characters the field holds.
   */
  protected function buildTitle(array $combination, array $title_options): string {
    // Defaults come from the same per-type configuration that
    // VariationBundle::generateTitle() and BundleItem::generateTitle() read,
    // so a bundle is named the same way whether its variation type generates
    // titles or this does it instead.
    $pattern = $title_options['item_pattern'] ?? $this->itemTitlePattern($title_options['bundle_item_type'] ?? 'default');
    $separator = $title_options['separator'] ?? $this->titleSeparator($title_options['variation_type'] ?? '');

    $parts = [];
    foreach ($combination as $item) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
      $variation = $item['variation'];
      $parts[] = str_replace(
        ['@quantity', '@title'],
        [(string) (int) $item['quantity'], (string) $variation->label()],
        $pattern,
      );
    }

    return mb_substr(implode($separator, $parts), 0, 255);
  }

  /**
   * The generated title pattern of a bundle item type.
   *
   * @param string $bundle_item_type_id
   *   The bundle item type the items are created as.
   *
   * @return string
   *   The pattern.
   */
  protected function itemTitlePattern(string $bundle_item_type_id): string {
    /** @var \Drupal\commerce_variation_bundle\Entity\BundleItemTypeInterface|null $type */
    $type = $this->entityTypeManager->getStorage('commerce_bundle_item_type')->load($bundle_item_type_id);

    return $type?->getTitlePattern() ?: BundleItemTypeInterface::DEFAULT_TITLE_PATTERN;
  }

  /**
   * The bundle title separator of a variation type.
   *
   * @param string $variation_type_id
   *   The variation type the bundle variations are created as.
   *
   * @return string
   *   The separator.
   */
  protected function titleSeparator(string $variation_type_id): string {
    if (!$variation_type_id) {
      return VariationBundleInterface::DEFAULT_TITLE_SEPARATOR;
    }

    /** @var \Drupal\commerce_product\Entity\ProductVariationTypeInterface|null $type */
    $type = $this->entityTypeManager->getStorage('commerce_product_variation_type')->load($variation_type_id);

    return $type?->getThirdPartySetting('commerce_variation_bundle', 'title_separator') ?? VariationBundleInterface::DEFAULT_TITLE_SEPARATOR;
  }

  /**
   * {@inheritdoc}
   */
  public function isBundleProduct(ProductInterface $product): bool {
    return $this->getBundleVariationTypeId($product) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceProductTypeIds(): array {
    $variation_type_storage = $this->entityTypeManager->getStorage('commerce_product_variation_type');
    $source_types = [];

    /** @var \Drupal\commerce_product\Entity\ProductTypeInterface $product_type */
    foreach ($this->entityTypeManager->getStorage('commerce_product_type')->loadMultiple() as $product_type) {
      $is_bundle = FALSE;
      foreach ($product_type->getVariationTypeIds() as $variation_type_id) {
        /** @var \Drupal\commerce_product\Entity\ProductVariationTypeInterface|null $variation_type */
        $variation_type = $variation_type_storage->load($variation_type_id);
        if ($variation_type instanceof ProductVariationTypeInterface
          && $variation_type->hasTrait('purchasable_entity_variation_bundle')) {
          $is_bundle = TRUE;
          break;
        }
      }

      if (!$is_bundle) {
        $source_types[] = $product_type->id();
      }
    }

    return $source_types;
  }

  /**
   * Builds the SKU of one generated bundle variation.
   *
   * @param array[] $combination
   *   The combination, each element having a "variation" and a "quantity".
   * @param array $sku_options
   *   The SKU options, as described in the interface.
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The bundle product the variation is generated for.
   *
   * @return string
   *   The SKU, truncated to the 255 characters the field holds.
   */
  protected function buildSku(array $combination, array $sku_options, ProductInterface $product): string {
    $separator = $sku_options['separator'] ?? '-';
    $parts = [];

    foreach ($combination as $item) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
      $variation = $item['variation'];
      $part = ($sku_options['source'] ?? 'sku') === 'id'
        ? (string) $variation->id()
        : (string) $variation->getSku();

      // A quantity of one is the norm and adds nothing but noise, so only
      // multiples are spelled out.
      if (!empty($sku_options['include_quantities']) && $item['quantity'] > 1) {
        $part .= 'x' . $item['quantity'];
      }
      $parts[] = $part;
    }

    if (!empty($sku_options['include_product_id'])) {
      array_unshift($parts, (string) $product->id());
    }
    if (!empty($sku_options['prefix'])) {
      array_unshift($parts, (string) $sku_options['prefix']);
    }

    return substr(implode($separator, $parts), 0, 255);
  }

  /**
   * The bundle variation type of a product type, if it has one.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The bundle product.
   *
   * @return string|null
   *   The variation type id, or NULL when the product type has none carrying
   *   the bundle trait.
   */
  protected function getBundleVariationTypeId(ProductInterface $product): ?string {
    /** @var \Drupal\commerce_product\Entity\ProductTypeInterface|null $product_type */
    $product_type = $this->entityTypeManager
      ->getStorage('commerce_product_type')
      ->load($product->bundle());

    if (!$product_type) {
      return NULL;
    }

    foreach ($product_type->getVariationTypeIds() as $variation_type_id) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationTypeInterface|null $variation_type */
      $variation_type = $this->entityTypeManager
        ->getStorage('commerce_product_variation_type')
        ->load($variation_type_id);

      if ($variation_type instanceof ProductVariationTypeInterface
        && $variation_type->hasTrait('purchasable_entity_variation_bundle')) {
        return $variation_type_id;
      }
    }

    return NULL;
  }

  /**
   * The fields of a template variation worth copying onto generated ones.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $template
   *   The template variation.
   *
   * @return string[]
   *   Field names.
   */
  protected function getCopyableFields(ProductVariationInterface $template): array {
    $skip = ['sku', 'bundle_items', 'variation_id', 'uuid', 'langcode', 'product_id', 'default_langcode'];
    return array_values(array_diff(array_keys($template->getFieldDefinitions()), $skip));
  }

  /**
   * Returns the product variation storage.
   */
  protected function variationStorage(): ProductVariationStorageInterface {
    /** @var \Drupal\commerce_product\ProductVariationStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    return $storage;
  }

}
