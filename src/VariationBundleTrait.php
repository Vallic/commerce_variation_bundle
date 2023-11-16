<?php

namespace Drupal\commerce_variation_bundle;

use Drupal\commerce_product\Entity\ProductVariationInterface;

/**
 * Helper for variation bundle.
 */
trait VariationBundleTrait {

  /**
   * Agnostic method of interfaces to determine if something is bundle or not.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $product_variation
   *   The product variation.
   *
   * @return bool
   *   True if we have bundle items referenced.
   */
  public function isBundleActive(ProductVariationInterface $product_variation) {
    if ($product_variation->hasField('bundle_items') && !$product_variation->get('bundle_items')->isEmpty()) {
      return TRUE;
    }

    return FALSE;
  }

}
