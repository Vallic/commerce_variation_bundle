<?php

namespace Drupal\commerce_variation_bundle\Resolver;

use Drupal\commerce\Context;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_price\Resolver\PriceResolverInterface;
use Drupal\commerce_variation_bundle\Entity\VariationBundle;

/**
 * Provides the calculated price based on referenced bundle items.
 */
class VariationBundlePriceResolver implements PriceResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(PurchasableEntityInterface $entity, $quantity, Context $context) {
    if ($entity instanceof VariationBundle) {
      $bundle_items = $entity->getBundleItems();
      if (!$bundle_items) {
        return NULL;
      }

      if ($entity->isPercentageOffer()) {
        // When we use percentage discount to show price.
        return $entity->getBundlePrice();
      }
    }

    return NULL;
  }

}
