<?php

namespace Drupal\commerce_variation_bundle\Entity;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;

/**
 * {@inheritdoc}
 */
interface VariationBundleInterface extends ProductVariationInterface {

  /**
   * List of referenced bundle items.
   *
   * @return \Drupal\commerce_variation_bundle\Entity\BundleItem[]
   *   The array of variation bundle items.
   */
  public function getBundleItems();

  /**
   * List of all product variations referenced under bundle items.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface[]
   *   The array of product variations.
   */
  public function getBundleVariations();

  /**
   * Return list of all associated product variations.
   *
   * @return array
   *   List of product variation ids.
   */
  public function getBundleVariationIds();

  /**
   * Check if bundle items contains specific product variation.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The product variation.
   *
   * @return bool
   *   True if referenced bundle items contains product variation.
   */
  public function hasBundleVariation(ProductVariationInterface $variation);

  /**
   * Calculate original bundle price.
   *
   * @return \Drupal\commerce_price\Price|null
   *   Return price or null.
   */
  public function getBundlePrice(): ?Price;

  /**
   * Get bundle discount percentage.
   *
   * @return int
   *   Return integer value.
   */
  public function getBundleDiscount(): int;

  /**
   * Determine if we have percentage based price.
   *
   * @return bool
   *   Return true if we use percentage off price.
   */
  public function isPercentageOffer(): bool;

  /**
   * Determine if we need to split bundle after order is placed.
   *
   * @return bool
   *   Return true if bundle items are going to be separated.
   */
  public function shouldBundleSplit(): bool;

}
