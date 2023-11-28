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

  /**
   * Determine what attributes variations should use.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   *   The product variations.
   *
   * @return bool
   *   True if we need to use default attributes.
   */
  public function useDefaultAttributes(array $variations): bool {
    if (count($variations) === 0) {
      return TRUE;
    }
    foreach ($variations as $variation) {
      // If we have no bundle variation, render normal widget.
      if (!$this->isBundleActive($variation)) {
        return TRUE;
      }

      // If we have normal attributes on use on bundle variation,
      // use default widget.
      if (!empty($variation->getAttributeValues())) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
