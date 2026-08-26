<?php

namespace Drupal\commerce_variation_bundle;

/**
 * What a generation run produced.
 */
final class BundleVariationGenerationResult {

  /**
   * Constructs the result.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $created
   *   The bundle variations that were created, in generation order.
   * @param int $duplicates
   *   How many combinations were skipped because their SKU already existed.
   * @param int $skipped
   *   How many combinations a subscriber skipped. Combinations removed from
   *   GenerateVariationsEvent are not counted here - they never reached
   *   generation.
   */
  public function __construct(
    protected array $created = [],
    protected int $duplicates = 0,
    protected int $skipped = 0,
  ) {}

  /**
   * The variations that were created.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface[]
   *   The created variations.
   */
  public function getCreated(): array {
    return $this->created;
  }

  /**
   * How many variations were created.
   */
  public function getCreatedCount(): int {
    return count($this->created);
  }

  /**
   * How many combinations were skipped for having a duplicate SKU.
   */
  public function getDuplicateCount(): int {
    return $this->duplicates;
  }

  /**
   * How many combinations a subscriber skipped.
   */
  public function getSkippedCount(): int {
    return $this->skipped;
  }

}
