<?php

namespace Drupal\commerce_variation_bundle\Entity;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining a product variation bundle entity type.
 */
interface BundleItemInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

  /**
   * Get assigned variation.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface
   *   The product variation
   */
  public function getVariation(): ProductVariationInterface;

  /**
   * Gets the product variation ID.
   *
   * @return int
   *   The product variation ID.
   */
  public function getVariationId(): int;

  /**
   * Gets the bundle item title.
   *
   * @return string|null
   *   The bundle item title
   */
  public function getTitle(): string|null;

  /**
   * Sets the bundle item title.
   *
   * @param string $title
   *   The bundle item title.
   *
   * @return $this
   */
  public function setTitle(string $title): static;

  /**
   * Gets the bundle item quantity.
   *
   * @return string
   *   The bundle item quantity
   */
  public function getQuantity(): string;

  /**
   * Sets the bundle item quantity.
   *
   * @param string $quantity
   *   The bundle item quantity.
   *
   * @return $this
   */
  public function setQuantity(string $quantity): static;

  /**
   * Returns computed price.
   *
   * @return \Drupal\commerce_price\Price
   *   Return computed price.
   */
  public function getPrice(): Price;

}
