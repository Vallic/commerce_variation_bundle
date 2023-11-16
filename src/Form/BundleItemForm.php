<?php

namespace Drupal\commerce_variation_bundle\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the product variation bundle entity edit forms.
 */
class BundleItemForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $result = parent::save($form, $form_state);
    $form_state->setRedirect('entity.commerce_bundle_item.collection');
    return $result;
  }

}
