<?php

namespace Drupal\commerce_variation_bundle\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Verifies that referenced variation is not of VariationBundleInterface.
 */
#[Constraint(
    id: "DisallowVariationBundle",
    label: new TranslatableMarkup('Valid product variation reference', [], ['context' => 'Validation'])
)]
class DisallowVariationBundle extends SymfonyConstraint {

  /**
   * The default violation message.
   *
   * @var string
   */
  public $message = 'You cannot reference another Product Variation Bundle inside another one.';

}
