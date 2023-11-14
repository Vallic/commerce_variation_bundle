<?php

namespace Drupal\commerce_variation_bundle;

use Drupal\commerce\Context;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\commerce_store\SelectStoreTrait;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;

/**
 * The computed field which exposes the current variation price.
 */
final class BundleItemComputedPrice extends FieldItemList {

  use ComputedItemListTrait;
  use SelectStoreTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue() {
    if (!$this->currentStore instanceof CurrentStoreInterface) {
      $this->currentStore = \Drupal::service('commerce_store.current_store');
    }
    /** @var \Drupal\commerce_variation_bundle\Entity\BundleItemInterface $bundle_item */
    $bundle_item = $this->getEntity();
    $context = new Context(\Drupal::currentUser()->getAccount(), $this->currentStore->getStore());
    $price = \Drupal::service('commerce_price.chain_price_resolver')->resolve($bundle_item->getVariation(), $bundle_item->getQuantity(), $context);
    $this->list[0] = $this->createItem(0, $price);
  }

}
