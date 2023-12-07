<?php

namespace Drupal\commerce_variation_bundle;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * {@inheritdoc}
 */
class VariationBundleSplitter implements VariationBundleSplitterInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Construct VariationBundleSplitter object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function split(OrderItemInterface $order_item): array {
    $purchased_entity = $order_item->getPurchasedEntity();
    if (!$purchased_entity instanceof VariationBundleInterface || empty($order_item->getData('bundle_items'))) {
      return [];
    }

    // Group to get total amount of each adjustment.
    $adjustments = $order_item->getAdjustments();
    $adjustments_amounts = $this->groupAdjustments($adjustments);

    // Get bundle data.
    $bundle_items_data = $order_item->getData('bundle_items');
    $count_items = count($bundle_items_data);

    // Loop and fill array of split adjustments.
    $bundle_amounts = [];
    foreach ($bundle_items_data as $bundle_id => $datum) {
      assert($datum instanceof BundleItemAmounts);
      // Calculate adjustments.
      $calculated_adjustments = $this->splitAdjustments($adjustments, $datum->getSplitPercentage());
      $datum->setAdjustments($calculated_adjustments);
      $bundle_amounts[$bundle_id] = $datum;

      // Subtract all adjustments which we split against original one.
      // If we have some amounts left, append it to last in line.
      foreach ($calculated_adjustments as $adjustment) {
        $amount = $adjustment->isNegative() ? $adjustment->getAmount()->multiply('-1') : $adjustment->getAmount();
        $adjustment_type = $adjustment->getType();
        $adjustments_amounts[$adjustment_type] = $adjustments_amounts[$adjustment_type]->subtract($amount);
      }
      --$count_items;

      // Last item. Check if original adjustments amounts minus split ones
      // equals 0. If not subtract that difference.
      // If is larger, we subtract. If is smaller, $amount
      // is going to be negative,
      // and therefore subtract negative is going add upon.
      // Usually this is one cent on taxes, etc... due to split of amount.
      if ($count_items === 0) {
        foreach ($adjustments_amounts as $type => $adjustments_amount) {
          if (!$adjustments_amount->isZero()) {
            foreach ($calculated_adjustments as $id => $calculated_adjustment) {
              if ($type === $calculated_adjustment->getType()) {
                $adjustment_array = $calculated_adjustment->toArray();
                $adjustment_array['amount'] = $calculated_adjustment->getAmount()->subtract($adjustments_amount);
                $updated_adjustment = new Adjustment($adjustment_array);
                $calculated_adjustments[$id] = $updated_adjustment;
              }
            }
            $datum->setAdjustments($adjustments);
          }
        }
      }
    }

    return $bundle_amounts;
  }

  /**
   * {@inheritdoc}
   */
  public function createOrderItems(OrderItemInterface $order_item): array {
    $order_items = [];
    $order_item_storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $bundle_amounts = $this->split($order_item);
    $order_item_quantity = $order_item->getQuantity();

    foreach ($bundle_amounts as $bundle_amount) {
      $order_item_values = [
        'type' => $order_item->bundle(),
        'purchased_entity' => $bundle_amount->getVariation(),
        'quantity' => $bundle_amount->getQuantity() * $order_item_quantity,
        'title' => $bundle_amount->getVariation()->getTitle(),
        'unit_price' => $bundle_amount->getPrice(),
        'adjustments' => $bundle_amount->getAdjustments(),
        // Write bundle source if needed for later troubleshooting.
        'data' => ['bundle_source' => $order_item->getPurchasedEntityId()],
      ];
      $new_order_item = $order_item_storage->create($order_item_values);
      $new_order_item->save();
      $order_items[] = $new_order_item;
    }

    return $order_items;
  }

  /**
   * Get total amounts per adjustments type.
   *
   * @param \Drupal\commerce_order\Adjustment[] $adjustments
   *   The list of adjustments.
   *
   * @return array
   *   List of total amounts per adjustment types.
   */
  protected function groupAdjustments(array $adjustments): array {
    $adjustments_amounts = [];
    $order_data = [];
    foreach ($adjustments as $adjustment) {
      $amount = $adjustment->isNegative() ? $adjustment->getAmount()->multiply('-1') : $adjustment->getAmount();

      // Map specific adjustments types.
      $adjustment_type = $adjustment->getType();

      if (!isset($order_data[$adjustment_type])) {
        $adjustments_amounts[$adjustment_type] = $amount;
      }
      else {
        $adjustments_amounts[$adjustment_type] = $order_data[$adjustment_type]->add($amount);
      }
    }

    return $adjustments_amounts;
  }

  /**
   * Get partial amounts per bundle item.
   *
   * @param array $adjustments
   *   List of adjustments.
   * @param string $percentage
   *   The percentage of bundle.
   *
   * @return array
   *   List of adjustments.
   */
  protected function splitAdjustments(array $adjustments, string $percentage): array {
    /** @var \Drupal\commerce_order\Adjustment[] $adjustments */
    foreach ($adjustments as $key => $adjustment) {
      $adjustments[$key] = $adjustment->multiply($percentage);
    }

    return $adjustments;
  }

}
