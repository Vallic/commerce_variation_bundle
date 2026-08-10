<?php

namespace Drupal\commerce_variation_bundle;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;

/**
 * {@inheritdoc}
 */
class VariationBundleSplitter implements VariationBundleSplitterInterface {

  /**
   * Construct VariationBundleSplitter object.
   */
  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

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

      // Last item. Whatever magnitude is left over after splitting - usually a
      // cent or two, because the split percentages are rounded - has to be
      // folded back in so the parts add up to the original adjustment.
      if ($count_items === 0) {
        foreach ($adjustments_amounts as $type => $adjustments_amount) {
          if (!$adjustments_amount->isZero()) {
            // Fold the rounding remainder into a single adjustment of this
            // type. Applying it to every same-type adjustment (e.g. two
            // promotions) would multiply the correction.
            foreach ($calculated_adjustments as $id => $calculated_adjustment) {
              if ($type === $calculated_adjustment->getType()) {
                $adjustment_array = $calculated_adjustment->toArray();
                // $adjustments_amount is a magnitude,
                // because groupAdjustments() flips negative adjustments when
                // totaling them. Growing the magnitude therefore means
                // subtracting from a negative adjustment but adding to a
                // positive one such as tax. A negative remainder means the
                // split overshot, and the same operations shrink the
                // magnitude instead.
                $adjustment_array['amount'] = $calculated_adjustment->isNegative()
                  ? $calculated_adjustment->getAmount()->subtract($adjustments_amount)
                  : $calculated_adjustment->getAmount()->add($adjustments_amount);
                $updated_adjustment = new Adjustment($adjustment_array);
                $calculated_adjustments[$id] = $updated_adjustment;
                break;
              }
            }
            $datum->setAdjustments($calculated_adjustments);
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
    foreach ($adjustments as $adjustment) {
      $amount = $adjustment->isNegative() ? $adjustment->getAmount()->multiply('-1') : $adjustment->getAmount();

      // Map specific adjustments types. Multiple adjustments can share a type
      // (e.g. two promotions), so accumulate rather than overwrite.
      $adjustment_type = $adjustment->getType();

      $adjustments_amounts[$adjustment_type] = isset($adjustments_amounts[$adjustment_type])
        ? $adjustments_amounts[$adjustment_type]->add($amount)
        : $amount;
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
