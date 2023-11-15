<?php

namespace Drupal\commerce_variation_bundle_stock;

use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;

/**
 * Handles stock for bundles.
 */
interface VariationBundleStockManagerInterface {

  /**
   * Set stock for bundle.
   */
  public function setStock(VariationBundleInterface $product_variation_bundle): void;

  /**
   * Get recalculated stock quantity.
   */
  public function recalculateStock(VariationBundleInterface $product_variation_bundle): ?int;

  /**
   * Withdraw stock from child variations.
   */
  public function withdrawStock(VariationBundleInterface $product_variation_bundle, int $quantity): void;

  /**
   * Update parent bundle if required.
   */
  public function checkBundleStock(int $variation_id): void;

}
