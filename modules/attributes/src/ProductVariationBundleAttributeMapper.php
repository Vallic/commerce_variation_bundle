<?php

namespace Drupal\commerce_variation_bundle_attributes;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_product\ProductVariationAttributeMapper;
use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;
use Drupal\commerce_variation_bundle\VariationBundleTrait;

/**
 * {@inheritdoc}
 */
class ProductVariationBundleAttributeMapper extends ProductVariationAttributeMapper {

  use VariationBundleTrait;

  /**
   * {@inheritdoc}
   */
  public function selectVariation(array $variations, array $attribute_values = []) {
    $selected_variation = NULL;
    foreach ($variations as $variation) {
      // If we are dealing with regular items, skip.
      if (!$this->isBundleActive($variation)) {
        return parent::selectVariation($variations, $attribute_values);
      }
      else {
        $valid_attributes = array_filter($attribute_values, function ($item) {
          return !empty($item);
        });
        $existing_attributes = $this->collectAttributes($variation);
        $match = 0;
        foreach ($valid_attributes as $type => $attribute_value) {
          // If passed attribute is empty, considered is as match, if
          // other variation does not have this attribute.
          if (!isset($existing_attributes[$type]) && empty($attribute_value)) {
            $match++;
          }

          if (isset($existing_attributes[$type][(int) $attribute_value])) {
            $match++;
          }
        }

        if (count($existing_attributes) === $match && count($valid_attributes) === $match) {
          $selected_variation = $variation;
          break;
        }
      }
    }

    return $selected_variation;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareAttributes(ProductVariationInterface $selected_variation, array $variations) {
    // If we are dealing with regular items, skip.
    if (!$this->isBundleActive($selected_variation)) {
      return parent::prepareAttributes($selected_variation, $variations);
    }
    $attributes = [];

    $product_variants = [];
    foreach ($variations as $variation) {
      $variation_bundles = $variation->get('bundle_items')->referencedEntities();
      foreach ($variation_bundles as $variation_bundle) {
        $product_variants[] = $variation_bundle->get('variation')->entity;
      }
    }

    foreach ($product_variants as $selected_variation) {
      $attributes += parent::prepareAttributes($selected_variation, $product_variants);
    }

    return $attributes;
  }

  /**
   * {@inheritdoc}
   */
  public function getAttributeValueId(VariationBundleInterface $product_variation, $field_name) {
    $field_map = [];
    foreach ($product_variation->getBundleVariations() as $variation) {
      $field_map = array_merge($field_map, $this->attributeFieldManager->getFieldMap($variation->bundle()));
    }
    $field_map = array_column($field_map, 'field_name');
    $attribute_ids = [];
    foreach ($field_map as $field_id) {
      foreach ($product_variation->getBundleVariations() as $variation) {
        if ($field_name === $field_id && $variation->hasField($field_id)) {
          $field = $variation->get($field_id);
          if (!$field->isEmpty()) {
            $attribute_ids[$field_id] = $field->target_id;
          }
        }
      }
    }

    return $attribute_ids;

  }

  /**
   * Collect all possible values from bundle references.
   */
  public function collectAttributes(VariationBundleInterface $product_variation): array {
    $attributes = [];
    $child_variations = $product_variation->getBundleVariations();
    foreach ($child_variations as $child_variation) {
      $ids = $child_variation->getAttributeValueIds();
      foreach ($ids as $id => $value) {
        $attributes[$id][$value] = $value;
      }
    }

    return $attributes;
  }

}
