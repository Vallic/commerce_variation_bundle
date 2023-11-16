<?php

namespace Drupal\commerce_variation_bundle\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Verifies that referenced variation is not of VariationBundleInterface.
 *
 * @Constraint(
 *   id = "DisallowVariationBundle",
 *   label = @Translation("Valid product variation reference", context = "Validation")
 * )
 */
class DisallowVariationBundle extends Constraint {

  /**
   * The default violation message.
   *
   * @var string
   */
  public $message = 'You cannot reference another Product Variation Bundle inside another one.';

}
