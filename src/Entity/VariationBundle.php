<?php

namespace Drupal\commerce_variation_bundle\Entity;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;

/**
 * {@inheritdoc}
 */
class VariationBundle extends ProductVariation implements VariationBundleInterface {

  /**
   * {@inheritdoc}
   */
  public function getBundleItems() {
    return $this->getTranslatedReferencedEntities('bundle_items');
  }

  /**
   * {@inheritdoc}
   */
  public function getBundleVariations() {
    $variations = [];
    foreach ($this->getBundleItems() as $bundle_item) {
      $variations[] = $bundle_item->get('variation')->entity;
    }
    return $variations;
  }

  /**
   * {@inheritdoc}
   */
  public function getBundleVariationIds() {
    $variation_ids = [];
    foreach ($this->get('bundle_items') as $field_item) {
      $variation_ids[] = $field_item->target_id;
    }
    return $variation_ids;
  }

  /**
   * {@inheritdoc}
   */
  public function hasBundleVariation(ProductVariationInterface $variation) {
    return in_array($variation->id(), $this->getBundleVariationIds());
  }

  /**
   * Generates the variation title based on attribute values.
   *
   * @return string
   *   The generated value.
   */
  protected function generateTitle() {
    $bundle_items = $this->getBundleItems();
    if (!$bundle_items) {
      // Title generation is not possible when there is no bundle items.
      return parent::generateTitle();
    }

    $titles = array_map(function (BundleItemInterface $bundle_item) {
      return $bundle_item->getTitle();
    }, $bundle_items);

    return implode(' / ', $titles);
  }

  /**
   * {@inheritdoc}
   */
  public function getBundlePrice($adjusted = FALSE): ?Price {
    $calculated_price = NULL;
    foreach ($this->getBundleItems() as $bundle_item) {
      if (!$calculated_price) {
        $calculated_price = $bundle_item->getPrice()->multiply($bundle_item->getQuantity());
      }
      else {
        $calculated_price = $calculated_price->add($bundle_item->getPrice()->multiply($bundle_item->getQuantity()));
      }
    }

    if ($calculated_price && $adjusted && $this->isPercentageOffer()) {
      return \Drupal::service('commerce_price.rounder')->round($calculated_price->subtract($calculated_price->multiply($this->getBundleDiscount() / 100)));
    }

    return $calculated_price;
  }

  /**
   * {@inheritdoc}
   */
  public function getBundleDiscount(): int {
    return (int) $this->get('bundle_discount')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isPercentageOffer(): bool {
    return $this->getBundleDiscount() > 0;
  }

  /**
   * {@inheritdoc}
   */
  public function shouldBundleSplit(): bool {
    return (bool) $this->get('bundle_split')->value;
  }

}
