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

    $entity = $this->getEntity();

    $message_arguments = ['%label' => $entity->toLink()->toString()];
    $logger_arguments = [
      '%label' => $entity->label(),
      'link' => $entity->toLink($this->t('View'))->toString(),
    ];

    switch ($result) {
      case SAVED_NEW:
        $this->messenger()->addStatus($this->t('New variation bundle item %label has been created.', $message_arguments));
        $this->logger('commerce_variation_bundle')->notice('Created new variation bundle item %label', $logger_arguments);
        break;

      case SAVED_UPDATED:
        $this->messenger()->addStatus($this->t('The variation bundle item %label has been updated.', $message_arguments));
        $this->logger('commerce_variation_bundle')->notice('Updated variation bundle item %label.', $logger_arguments);
        break;
    }

    $form_state->setRedirect('entity.commerce_bundle_item.collection');

    return $result;
  }

}
