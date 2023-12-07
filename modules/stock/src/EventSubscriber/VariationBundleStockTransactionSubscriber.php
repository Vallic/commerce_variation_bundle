<?php

namespace Drupal\commerce_variation_bundle_stock\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_variation_bundle\Entity\VariationBundle;
use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;
use Drupal\commerce_variation_bundle_stock\VariationBundleStockManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
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
   * The config factory manager.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * List of bundle variations.
   */
  protected array $variationBundles = [];

  /**
   * List of non-bundle variations.
   */
  protected array $variations = [];

  /**
   * Constructs a CommerceStockTransactionSubscriber.
   *
   * @param \Drupal\commerce_variation_bundle_stock\VariationBundleStockManagerInterface $stock_manager
   *   The bundle stock manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(VariationBundleStockManagerInterface $stock_manager, ConfigFactoryInterface $config_factory) {
    $this->stockManager = $stock_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents() {
    return [
      'commerce_order.post_transition' => ['onPostTransition'],
      // Run last.
      'commerce_order.place.post_transition' => ['afterOrderPlace', -1000],
    ];
  }

  /**
   * Triggers updating parent bundle stock.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The event.
   */
  public function afterOrderPlace(WorkflowTransitionEvent $event) {
    $config = $this->configFactory->get('commerce_stock.core_stock_events');
    $complete_event_type = $config->get('core_stock_events_order_complete_event_type') ?? 'placed';
    // Only update a placed order if the matching configuration is set.
    if ($complete_event_type == 'placed') {
      // Create the complete transaction.
      $this->processBundleStock($event->getEntity());
    }
  }

  /**
   * Triggers updating parent bundle stock.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The event.
   */
  public function onPostTransition(WorkflowTransitionEvent $event) {
    $id = $event->getTransition()->getToState()->getId();
    $config = $this->configFactory->get('commerce_stock.core_stock_events');
    $complete_event_type = $config->get('core_stock_events_order_complete_event_type') ?? 'placed';
    if (($id == 'completed') && ($complete_event_type == 'completed')) {
      $this->processBundleStock($event->getEntity());
    }
  }

  /**
   * Logic for processing stock for bundle referenced items.
   */
  protected function processBundleStock(OrderInterface $order): void {
    $order_items = $order->getItems();
    $bundle_variation_ids = [];
    foreach ($order_items as $order_item) {
      $purchasable_entity = $order_item->getPurchasedEntity();
      if ($bundle_id = $order_item->getData('bundle_source')) {
        if (!isset($bundle_variation_ids[$bundle_id])) {
          $bundle_variation_ids[$bundle_id] = $bundle_id;
        }
      }
      // If order item is not coming from bundle, we need to update
      // any bundle which holds reference to this product variation.
      // Or if item is bundle, update child items.
      else {
        if ($purchasable_entity instanceof VariationBundleInterface) {
          $this->stockManager->withdrawStock($purchasable_entity, $order_item->getQuantity());
        }
        else {
          $this->variations[] = $order_item->getPurchasedEntityId();
        }
      }
    }

    foreach ($bundle_variation_ids as $bundle_variation_id) {
      $this->variationBundles[] = $bundle_variation_id;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function destruct() {
    foreach ($this->variationBundles as $variation_bundle_id) {
      if ($bundle_variation = VariationBundle::load($variation_bundle_id)) {
        $this->stockManager->setStock($bundle_variation);
      }
    }
    foreach ($this->variations as $variation) {
      $this->stockManager->checkBundleStock($variation);
    }
  }

}
