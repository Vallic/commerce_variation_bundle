<?php

namespace Drupal\commerce_variation_bundle\Event;

use Drupal\commerce\EventBase;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;

/**
 * Allows one bundle variation to be filled in or skipped before it is saved.
 *
 * Neither the variation nor its bundle items have been saved when this fires,
 * so skipping leaves nothing behind to clean up.
 *
 * @see \Drupal\commerce_variation_bundle\Event\VariationBundleEvents::GENERATE_VARIATION
 */
class GenerateVariationEvent extends EventBase {

  /**
   * Whether this combination should be skipped.
   */
  protected bool $skipped = FALSE;

  /**
   * Constructs the event.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The unsaved bundle variation, with the template's field values and the
   *   generated SKU already set.
   * @param \Drupal\commerce_variation_bundle\Entity\BundleItemInterface[] $bundleItems
   *   The unsaved bundle items, in source product order.
   * @param array[] $combination
   *   The combination this variation was built from - one entry per source
   *   product, each with a "variation" and an integer "quantity".
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The bundle product the variation is generated for.
   */
  public function __construct(
    protected ProductVariationInterface $variation,
    protected array $bundleItems,
    protected array $combination,
    protected ProductInterface $product,
  ) {}

  /**
   * Gets the unsaved bundle variation.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface
   *   The variation, which may be modified in place.
   */
  public function getVariation(): ProductVariationInterface {
    return $this->variation;
  }

  /**
   * Gets the unsaved bundle items.
   *
   * @return \Drupal\commerce_variation_bundle\Entity\BundleItemInterface[]
   *   The bundle items, which may be modified in place.
   */
  public function getBundleItems(): array {
    return $this->bundleItems;
  }

  /**
   * Gets the combination this variation was built from.
   *
   * @return array[]
   *   The combination.
   */
  public function getCombination(): array {
    return $this->combination;
  }

  /**
   * Gets the bundle product.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface
   *   The product.
   */
  public function getProduct(): ProductInterface {
    return $this->product;
  }

  /**
   * Skips this combination.
   *
   * The variation and its bundle items are discarded unsaved, and the
   * combination is counted as skipped in the result.
   *
   * @return $this
   */
  public function skip(): static {
    $this->skipped = TRUE;
    return $this;
  }

  /**
   * Whether this combination is skipped.
   *
   * @return bool
   *   TRUE when it will not be created.
   */
  public function isSkipped(): bool {
    return $this->skipped;
  }

}
