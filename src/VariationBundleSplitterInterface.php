<?php

namespace Drupal\commerce_variation_bundle;

use Drupal\commerce_order\Entity\OrderItemInterface;

/**
 * Split adjustments per each bundle item.
 */
interface VariationBundleSplitterInterface {

  /**
   * Split adjustments.
   *
   * @return \Drupal\commerce_variation_bundle\BundleItemAmounts[]
   *   The bundle amounts array.
   */
  public function split(OrderItemInterface $order_item): array;

  /**
   * Create order items for each variation.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface[]
   *   The newly created order items.
   */
  public function createOrderItems(OrderItemInterface $order_item): array;

}
