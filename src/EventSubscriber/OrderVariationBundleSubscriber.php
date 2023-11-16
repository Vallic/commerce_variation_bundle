<?php

namespace Drupal\commerce_variation_bundle\EventSubscriber;

use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;
use Drupal\commerce_variation_bundle\VariationBundleSplitterInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Split bundle on separate order items post order placement.
 */
class OrderVariationBundleSubscriber implements EventSubscriberInterface {

  /**
   * The variation bundle splitter.
   */
  protected VariationBundleSplitterInterface $bundleSplitter;

  /**
   * List of orders to update.
   */
  protected array $orders = [];

  /**
   * Constructs a new OrderVariationBundleSubscriber object.
   *
   * @param \Drupal\commerce_variation_bundle\VariationBundleSplitterInterface $bundle_splitter
   *   The bundle splitter.
   */
  public function __construct(VariationBundleSplitterInterface $bundle_splitter) {
    $this->bundleSplitter = $bundle_splitter;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Execute events as early possible, so that any other business
    // logic dependable on bundle split is executed later.
    return [
      'commerce_order.place.pre_transition' => ['onOrderPlace', -1000],
    ];
  }

  /**
   * Triggers sending case if integration is enabled for product variation.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The event.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $order_items = $order->getItems();
    foreach ($order_items as $order_item) {
      $purchased_entity = $order_item->getPurchasedEntity();
      if ($purchased_entity instanceof VariationBundleInterface && $purchased_entity->shouldBundleSplit()) {
        $order->removeItem($order_item);
        foreach ($this->bundleSplitter->createOrderItems($order_item) as $item) {
          $order->addItem($item);
        }
      }
    }
  }

}
