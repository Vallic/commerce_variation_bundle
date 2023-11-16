<?php

namespace Drupal\commerce_variation_bundle\Plugin\Validation\Constraint;

use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the DisallowVariationBundle constraint.
 */
class DisallowVariationBundleValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {
    assert($value instanceof EntityReferenceFieldItemListInterface);
    $product_variations = $value->referencedEntities();
    foreach ($product_variations as $delta => $product_variation) {
      if ($product_variation instanceof VariationBundleInterface) {
        $this->context->buildViolation($constraint->message)
          ->atPath($delta . '.target_id')
          ->setInvalidValue($product_variation->getTitle())
          ->addViolation();
      }
    }
  }

}
