<?php

namespace Drupal\commerce_variation_bundle;

use Drupal\commerce_product\Entity\ProductInterface;

/**
 * Generates bundle variations from a selection of source variations.
 *
 * Split out of GenerateBundleVariationsForm so the generation is usable
 * without it: a migration, a drush command, a queue worker or a different UI
 * can all call this, and only the form has to know about form state.
 */
interface BundleVariationGeneratorInterface {

  /**
   * Builds the combinations a selection produces.
   *
   * Exposed separately from generate() so a caller can count or preview them -
   * the form uses it to warn before a large run.
   *
   * @param array $selection
   *   Keyed by source product id, each with:
   *   - variations: \Drupal\commerce_product\Entity\ProductVariationInterface[]
   *   - quantity: int, how many units of that variation the bundle holds.
   *
   * @return array[]
   *   One entry per combination, each a list with one item per source product,
   *   each item an array with a "variation" and an integer "quantity".
   */
  public function buildCombinations(array $selection): array;

  /**
   * The combinations that would actually be created, after subscribers.
   *
   * Builds the cartesian product and dispatches
   * VariationBundleEvents::GENERATE_VARIATIONS over it, so the answer is what
   * generate() would create rather than the raw arithmetic. A UI counting
   * combinations must use this: reporting the raw product would overstate the
   * run and, where the count drives a limit, refuse one that a subscriber had
   * already cut to size.
   *
   * Subscribers run on every call, including from a preview, so they are
   * expected to be cheap and free of side effects.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The bundle product the variations would be generated for.
   * @param array $selection
   *   The selection, as described in ::buildCombinations().
   * @param array $options
   *   (optional) Accepts "variation_type"; derived from the product when
   *   omitted, as in ::generate().
   *
   * @return array[]
   *   The combinations left after subscribers.
   */
  public function getCombinations(ProductInterface $product, array $selection, array $options = []): array;

  /**
   * Generates the bundle variations and adds them to the product.
   *
   * Dispatches VariationBundleEvents::GENERATE_VARIATIONS before creating
   * anything, and VariationBundleEvents::GENERATE_VARIATION for each
   * combination before it is saved.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The bundle product to add the variations to. Saved by this method.
   * @param array $selection
   *   The selection, as described in ::buildCombinations().
   * @param array $options
   *   (optional) Any of:
   *   - variation_type: string, the variation type to create. Derived from the
   *     product's bundle variation type when omitted.
   *   - template: \Drupal\commerce_product\Entity\ProductVariationInterface, a
   *     variation whose field values are copied onto each generated one.
   *   - fields: string[], which of the template's fields to copy. All
   *     non-base fields when omitted.
   *   - sku: array, SKU generation options - "prefix" (string), "separator"
   *     (string), "include_quantities" (bool), "source" (either "sku" or
   *     "id", where each part of the SKU comes from).
   *   - title: array, title generation options - "generate" (bool, off by
   *     default), "separator" (string, " + " by default) and
   *     "include_quantities" (bool, on by default). Ignored when the variation
   *     type generates its own titles from attributes, which would overwrite
   *     anything set here on save.
   *
   * @return \Drupal\commerce_variation_bundle\BundleVariationGenerationResult
   *   What was created and what was skipped.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   When a variation, bundle item or the product cannot be saved.
   * @throws \InvalidArgumentException
   *   When no variation type is given and none can be derived.
   */
  public function generate(ProductInterface $product, array $selection, array $options = []): BundleVariationGenerationResult;

  /**
   * Whether a product is itself a bundle.
   *
   * Bundles are not usable as sources for another bundle: the split, pricing
   * and stock handling all assume a bundle item points at a plain variation.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product to test.
   *
   * @return bool
   *   TRUE when its product type has a bundle variation type.
   */
  public function isBundleProduct(ProductInterface $product): bool;

  /**
   * The product types that are usable as bundle sources.
   *
   * Intended for an entity reference selection - passing these as
   * "target_bundles" keeps bundle products out of a product autocomplete
   * rather than rejecting them once chosen.
   *
   * @return string[]
   *   Product type ids, excluding every type that has a bundle variation type.
   */
  public function getSourceProductTypeIds(): array;

}
