<?php

namespace Drupal\commerce_variation_bundle_stock\EventSubscriber;

use Drupal\commerce_product\Event\ProductEvents;
use Drupal\commerce_product\Event\ProductVariationEvent;
use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;
use Drupal\commerce_variation_bundle_stock\VariationBundleStockManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * {@inheritdoc}
 */
class ProductVariationSubscriber implements EventSubscriberInterface {


  /**
   * The bundle stock manager.
   */
  protected VariationBundleStockManagerInterface $stockManager;

  /**
   * Constructs a CommerceStockTransactionSubscriber.
   *
   * @param \Drupal\commerce_variation_bundle_stock\VariationBundleStockManagerInterface $stock_manager
   *   The bundle stock manager.
   */
  public function __construct(VariationBundleStockManagerInterface $stock_manager) {
    $this->stockManager = $stock_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ProductEvents::PRODUCT_VARIATION_INSERT => 'onInsert',
      ProductEvents::PRODUCT_VARIATION_UPDATE => 'onUpdate',
    ];
  }

  /**
   * When a variation is created, set stock.
   *
   * @param \Drupal\commerce_product\Event\ProductVariationEvent $event
   *   The product variation event.
   */
  public function onInsert(ProductVariationEvent $event) {
    $product_variation = $event->getProductVariation();
    if ($product_variation instanceof VariationBundleInterface) {
      $this->stockManager->setStock($product_variation);
    }
  }

  /**
   * When a variation is update, check for stock changes.
   *
   * @param \Drupal\commerce_product\Event\ProductVariationEvent $event
   *   The product variation event.
   */
  public function onUpdate(ProductVariationEvent $event) {
    $product_variation = $event->getProductVariation();
    if ($product_variation instanceof VariationBundleInterface) {
      $this->stockManager->setStock($product_variation);
    }
  }

}
