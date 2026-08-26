<?php

namespace Drupal\commerce_variation_bundle\Event;

use Drupal\commerce\EventBase;
use Drupal\commerce_product\Entity\ProductInterface;

/**
 * Allows the combinations to be altered before any variation is created.
 *
 * @see \Drupal\commerce_variation_bundle\Event\VariationBundleEvents::GENERATE_VARIATIONS
 */
class GenerateVariationsEvent extends EventBase {

  /**
   * Constructs the event.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The bundle product the variations are generated for.
   * @param array[] $combinations
   *   The combinations to create. Each is a list of items, one per source
   *   product, each an array with a "variation"
   *   (\Drupal\commerce_product\Entity\ProductVariationInterface) and an
   *   integer "quantity".
   * @param array $selection
   *   The selection the combinations were built from, keyed by source product
   *   id, each with "variations" and "quantity". Read-only context: changing
   *   it has no effect, the combinations are what get created.
   * @param string $variationTypeId
   *   The variation type the bundle variations are created as.
   */
  public function __construct(
    protected ProductInterface $product,
    protected array $combinations,
    protected array $selection,
    protected string $variationTypeId,
  ) {}

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
   * Gets the combinations that will be created.
   *
   * @return array[]
   *   The combinations.
   */
  public function getCombinations(): array {
    return $this->combinations;
  }

  /**
   * Sets the combinations that will be created.
   *
   * Values are reindexed, so a subscriber can filter with array_filter()
   * without having to renumber the result.
   *
   * @param array[] $combinations
   *   The combinations to keep.
   *
   * @return $this
   */
  public function setCombinations(array $combinations): static {
    $this->combinations = array_values($combinations);
    return $this;
  }

  /**
   * Gets the selection the combinations were built from.
   *
   * @return array
   *   The selection, keyed by source product id.
   */
  public function getSelection(): array {
    return $this->selection;
  }

  /**
   * Gets the variation type the bundle variations are created as.
   *
   * @return string
   *   The variation type id.
   */
  public function getVariationTypeId(): string {
    return $this->variationTypeId;
  }

}
