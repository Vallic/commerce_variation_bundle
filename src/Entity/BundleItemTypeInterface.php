<?php

namespace Drupal\commerce_variation_bundle\Entity;

use Drupal\commerce\Entity\CommerceBundleEntityInterface;

/**
 * Provides an interface defining a product variation bundle entity type.
 */
interface BundleItemTypeInterface extends CommerceBundleEntityInterface {

  /**
   * Gets whether the bundle item title should be automatically generated.
   *
   * @return bool
   *   Whether the bundle itemn title should be automatically generated.
   */
  public function shouldGenerateTitle();

  /**
   * Sets whether the bundle item title should be automatically generated.
   *
   * @param bool $generate_title
   *   Whether the bundle item title should be automatically generated.
   *
   * @return $this
   */
  public function setGenerateTitle($generate_title);

}
