<?php

namespace Drupal\commerce_variation_bundle_stock\EventSubscriber;

use Drupal\commerce_stock_local\Event\LocalStockTransactionEvent;
use Drupal\commerce_stock_local\Event\LocalStockTransactionEvents;
use Drupal\commerce_variation_bundle\Entity\VariationBundle;
use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;
use Drupal\commerce_variation_bundle_stock\VariationBundleStockManagerInterface;
use Drupal\Core\DestructableInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Withdraw quantities for bundle items.
 */
class VariationBundleStockTransactionSubscriber implements EventSubscriberInterface, DestructableInterface {

  /**
   * The bundle stock manager.
   */
  protected VariationBundleStockManagerInterface $stockManager;

  /**
   * List of variation bundles.
   */
  protected array $variationBundles = [];

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
   * {@inheritDoc}
   */
  public static function getSubscribedEvents() {
    return [
      LocalStockTransactionEvents::LOCAL_STOCK_TRANSACTION_INSERT => 'onTransactionInsert',
      'commerce_order.place.post_transition' => ['afterOrderPlace'],

    ];
  }

  /**
   * Invalidate the cache for the purchased entity.
   *
   * @param \Drupal\commerce_stock_local\Event\LocalStockTransactionEvent $event
   *   The event.
   */
  public function onTransactionInsert(LocalStockTransactionEvent $event) {
    $purchasable_entity = $event->getEntity();
    $quantity = $event->getQuantity();
    // If we deduct quantity either from bundle, we need reflect that on
    // child items. If we deduct/add from regular product variation,
    // we need to find out if there is bundle using it, and recalculate stock
    // for them.
    if ($purchasable_entity instanceof VariationBundleInterface) {
      if (!$purchasable_entity->shouldBundleSplit()) {
        if ($quantity < 0) {
          $this->variationBundles[] = ['entity' => $purchasable_entity, 'quantity' => $quantity];
        }
      }
    }
  }

  /**
   * Triggers updating parent bundle stock.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The event.
   */
  public function afterOrderPlace(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $order_items = $order->getItems();
    $bundle_variation_ids = [];
    foreach ($order_items as $order_item) {
      if ($bundle_id = $order_item->getData('bundle_source')) {
        if (!isset($bundle_variation_ids[$bundle_id])) {
          $bundle_variation_ids[$bundle_id] = $bundle_id;
        }
      }
    }

    foreach ($bundle_variation_ids as $bundle_variation_id) {
      if ($bundle_variation = VariationBundle::load($bundle_variation_id)) {
        $this->stockManager->setStock($bundle_variation);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function destruct() {
    foreach ($this->variationBundles as $item) {
      $this->stockManager->withdrawStock($item['entity'], $item['quantity']);
    }
  }

}
