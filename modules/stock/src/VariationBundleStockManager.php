<?php

namespace Drupal\commerce_variation_bundle_stock;

use Drupal\commerce_stock\ContextCreatorTrait;
use Drupal\commerce_stock\StockServiceManagerInterface;
use Drupal\commerce_stock\StockTransactionsInterface;
use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * {@inheritdoc}
 */
class VariationBundleStockManager implements VariationBundleStockManagerInterface {

  use ContextCreatorTrait;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The local stock checker.
   */
  protected StockServiceManagerInterface $stockServiceManager;

  /**
   * Construct VariationBundleStockManager object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_stock\StockServiceManagerInterface $stock_service_manager
   *   The stock manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, StockServiceManagerInterface $stock_service_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->stockServiceManager = $stock_service_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function setStock(VariationBundleInterface $product_variation_bundle): void {
    $bundle_items = $product_variation_bundle->getBundleItems();

    if (empty($bundle_items)) {
      return;
    }
    $stock = $this->stockServiceManager->getStockLevel($product_variation_bundle);
    $updated = $this->recalculateStock($product_variation_bundle);
    $difference = $updated - $stock;

    if (!empty($difference)) {
      $location = $this->stockServiceManager->getTransactionLocation($this->getContext($product_variation_bundle), $product_variation_bundle, $updated);
      if (empty($location)) {
        // If we have no location, something isn't properly configured.
        throw new \RuntimeException('The StockServiceManager didn\'t return a location. Make sure your store is set up correctly?');
      }

      // TBD - unit cost, currency, zone.
      $this->stockServiceManager->createTransaction($product_variation_bundle, $location->getId(), '', $difference, 0.00, NULL, $difference > 0 ? StockTransactionsInterface::STOCK_IN : StockTransactionsInterface::STOCK_OUT);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function recalculateStock(VariationBundleInterface $product_variation_bundle): ?int {
    $bundle_items = $product_variation_bundle->getBundleItems();
    $stock_status = 0;
    $possible_stock = [];
    foreach ($bundle_items as $bundle_item) {
      $quantity = $bundle_item->getQuantity();
      $stock = $this->stockServiceManager->getStockLevel($bundle_item->getVariation());
      if ($stock - $quantity <= 0) {
        return $stock_status;
      }
      $possible_stock[$bundle_item->getVariationId()] = $stock / $quantity;
    }

    return (int) min($possible_stock);
  }

  /**
   * {@inheritdoc}
   */
  public function withdrawStock(VariationBundleInterface $product_variation_bundle, int $quantity): void {
    $bundle_items = $product_variation_bundle->getBundleItems();
    foreach ($bundle_items as $bundle_item) {
      $bundle_item_quantity = $bundle_item->getQuantity();
      $bundle_item_variation = $bundle_item->getVariation();
      $total = $bundle_item_quantity * $quantity;
      $location = $this->stockServiceManager->getTransactionLocation($this->getContext($bundle_item_variation), $bundle_item_variation, $total);
      if (empty($location)) {
        // If we have no location, something isn't properly configured.
        throw new \RuntimeException('The StockServiceManager didn\'t return a location. Make sure your store is set up correctly?');
      }

      // TBD - unit cost, currency, zone.
      $this->stockServiceManager->createTransaction($bundle_item_variation, $location->getId(), '', $total, 0.00, NULL, StockTransactionsInterface::STOCK_SALE);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkBundleStock(int $variation_id): void {
    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $bundle_item_storage = $this->entityTypeManager->getStorage('commerce_bundle_item');
    $bundle_items = $bundle_item_storage->loadByProperties([
      'variation' => $variation_id,
    ]);

    foreach ($bundle_items as $bundle_item) {
      /** @var \Drupal\commerce_variation_bundle\Entity\VariationBundleInterface[] $variation_bundles */
      $variation_bundles = $variation_storage->loadByProperties(['bundle_items' => $bundle_item->id()]);
      foreach ($variation_bundles as $variation_bundle) {
        $stock = $this->stockServiceManager->getStockLevel($variation_bundle);
        if ($stock > 0) {
          $this->setStock($variation_bundle);
        }
      }
    }
  }

}
