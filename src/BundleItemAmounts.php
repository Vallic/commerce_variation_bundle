<?php

namespace Drupal\commerce_variation_bundle;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;

/**
 * Contains all relevant bundle information for split.
 */
class BundleItemAmounts {

  public const BUNDLE_ITEM_AMOUNTS_REQUIRED_PROPERTIES = [
    'quantity',
    'variation_id',
    'price',
    'split_percentage',
  ];

  /**
   * Variation id.
   */
  protected mixed $variationId;

  /**
   * The bundle item price.
   */
  protected Price $price;

  /**
   * The item quantity.
   */
  protected string $quantity;

  /**
   * The list of adjustments associated with bundle item.
   *
   * @var \Drupal\commerce_order\Adjustment[]
   */
  protected array $adjustments;

  /**
   * Split percentage of entire bundle.
   */
  protected string $splitPercentage;

  /**
   * Constructs a new BundleItemAmounts instance.
   *
   * @param array $definition
   *   The definition.
   */
  public function __construct(array $definition) {
    foreach (self::BUNDLE_ITEM_AMOUNTS_REQUIRED_PROPERTIES as $required_property) {
      if (empty($definition[$required_property])) {
        throw new \InvalidArgumentException(sprintf('Missing or empty required property "%s".', $required_property));
      }
      if ($required_property === 'price') {
        if (!$definition[$required_property] instanceof Price) {
          throw new \InvalidArgumentException(sprintf('Price property should be instance of "%s".', Price::class));
        }
      }
    }

    $this->variationId = $definition['variation_id'];
    $this->quantity = $definition['quantity'];
    $this->price = $definition['price'];
    $this->adjustments = $definition['adjustments'] ?? [];
    $this->splitPercentage = $definition['split_percentage'];
  }

  /**
   * Get referenced variation id.
   */
  public function getVariationId(): string {
    return $this->variationId;
  }

  /**
   * Get referenced product variation.
   */
  public function getVariation(): ProductVariationInterface {
    return ProductVariation::load($this->variationId);
  }

  /**
   * Get product variation price.
   */
  public function getPrice(): Price {
    return $this->price;
  }

  /**
   * Get percentage of bundle.
   */
  public function getSplitPercentage(): string {
    return $this->splitPercentage;
  }

  /**
   * Get item quantity.
   */
  public function getQuantity(): string {
    return $this->quantity;
  }

  /**
   * Get all adjustments.
   *
   * @return \Drupal\commerce_order\Adjustment[]
   *   List of adjustments.
   */
  public function getAdjustments(): array {
    return $this->adjustments;
  }

  /**
   * Set adjustments.
   *
   * @return $this
   */
  public function setAdjustments(array $adjustments) {
    $this->adjustments = $adjustments;
    return $this;
  }

  /**
   * Return array.
   */
  public function toArray(): array {
    return [
      'variation_id' => $this->variationId,
      'price' => $this->price,
      'quantity' => $this->quantity,
      'adjustments' => $this->adjustments,
      'split_percentage' => $this->splitPercentage,
    ];
  }

}
