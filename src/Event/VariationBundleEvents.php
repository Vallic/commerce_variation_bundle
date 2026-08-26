<?php

namespace Drupal\commerce_variation_bundle\Event;

/**
 * Defines the events dispatched while bundle variations are generated.
 */
final class VariationBundleEvents {

  /**
   * Fired once, after the combinations are built and before any is created.
   *
   * The combinations are alterable, so a subscriber decides which of them
   * become variations. Generating from the cartesian product of every source
   * variation is only ever a starting point: which combinations are worth
   * selling is a question about the catalogue, not about bundles, and the
   * module cannot answer it. A shop combining four products of 4, 8, 8 and 3
   * variations is offered 768 combinations while perhaps a dozen are
   * sellable - the rest pair sizes, shades or pack counts that do not go
   * together.
   *
   * Removing combinations here is much cheaper than creating variations and
   * deleting them afterwards, and it happens before any entity is written.
   *
   * @Event
   *
   * @see \Drupal\commerce_variation_bundle\Event\GenerateVariationsEvent
   */
  const GENERATE_VARIATIONS = 'commerce_variation_bundle.generate_variations';

  /**
   * Fired for each combination, after the entities are built, before saving.
   *
   * Both the bundle variation and its bundle items are still unsaved, so a
   * subscriber can set field values, override the generated SKU or title, or
   * skip this one combination outright.
   *
   * @Event
   *
   * @see \Drupal\commerce_variation_bundle\Event\GenerateVariationEvent
   */
  const GENERATE_VARIATION = 'commerce_variation_bundle.generate_variation';

}
