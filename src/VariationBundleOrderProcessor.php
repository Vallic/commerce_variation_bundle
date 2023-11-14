<?php

namespace Drupal\commerce_variation_bundle;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderProcessorInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Applies bundle savings to orders during the order refresh process.
 */
class VariationBundleOrderProcessor implements OrderProcessorInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function process(OrderInterface $order) {
    $order_items = $order->getItems();

    if (!$order_items) {
      return;
    }

    foreach ($order_items as $order_item) {
      $purchased_entity = $order_item->getPurchasedEntity();
      if ($purchased_entity instanceof VariationBundleInterface) {
        // During percentage based pricing, we have resolved price
        // as unit price -  full bundle price.
        // Fetch bundle price with percentage applied.
        if ($purchased_entity->isPercentageOffer()) {
          $bundle_price = $purchased_entity->getBundlePrice(TRUE);
          $full_price = $order_item->getUnitPrice();
        }
        // During regular price field or price lists get full original price
        // and set it as override unit price.
        // We can't have it resolved immediately, because it's a chicken and egg
        // problem.
        else {
          $full_price = $purchased_entity->getBundlePrice();
          $bundle_price = $order_item->getUnitPrice();
          if (!$order_item->isUnitPriceOverridden()) {
            $order_item->setUnitPrice($full_price);
          }
        }

        $adjustment_amount = $bundle_price->subtract($full_price);

        // Adjustment should be negative, and not zero.
        if ($adjustment_amount->isPositive() || $adjustment_amount->isZero()) {
          continue;
        }

        $order_item->addAdjustment(new Adjustment([
          'type' => 'bundle_saving',
          'amount' => $adjustment_amount->multiply($order_item->getQuantity()),
          'label' => $this->t('Bundle saving'),
          'percentage' => $purchased_entity->isPercentageOffer() ? (string) ($purchased_entity->getBundleDiscount() / 100) : NULL,
          'source_id' => $purchased_entity->id(),
        ]));

        $items = [];
        foreach ($purchased_entity->getBundleItems() as $bundle_item) {
          $percentage_of_bundle = Calculator::divide($bundle_item->getPrice()->multiply($bundle_item->getQuantity())->getNumber(), $order_item->getUnitPrice()->getNumber());
          $items[$bundle_item->id()] = new BundleItemAmounts([
            'variation_id' => $bundle_item->getVariationId(),
            'price' => $bundle_item->getPrice(),
            'quantity' => $bundle_item->getQuantity(),
            'split_percentage' => Calculator::round($percentage_of_bundle, 2),
          ]);
        }

        // Store original data. If split are occurred later, prices
        // or bundle contents could change.
        $order_item->setData('bundle_items', $items);
        $order_item->setData('bundle_discount', $purchased_entity->getBundleDiscount());
      }
    }
  }

}
