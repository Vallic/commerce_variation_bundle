<?php

namespace Drupal\commerce_variation_bundle\Entity;

use Drupal\commerce\Entity\CommerceBundleEntityInterface;

/**
 * Provides an interface defining a product variation bundle entity type.
 */
interface BundleItemTypeInterface extends CommerceBundleEntityInterface {

  /**
   * The title pattern used when a type has none of its own.
   *
   * Reproduces the format bundle item titles were hardcoded to before this
   * became configurable, so existing titles regenerate unchanged.
   */
  const DEFAULT_TITLE_PATTERN = '@quantityx @title';

  /**
   * Gets whether the bundle item title should be automatically generated.
   *
   * @return bool
   *   Whether the bundle item title should be automatically generated.
   */
  public function shouldGenerateTitle();

  /**
   * Gets the pattern a generated bundle item title is built from.
   *
   * @return string
   *   The pattern, containing the @quantity and @title placeholders.
   */
  public function getTitlePattern(): string;

  /**
   * Sets the pattern a generated bundle item title is built from.
   *
   * @param string $pattern
   *   The pattern, which may contain @quantity and @title.
   *
   * @return $this
   */
  public function setTitlePattern(string $pattern): static;

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
