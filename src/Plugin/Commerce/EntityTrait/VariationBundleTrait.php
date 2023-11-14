<?php

namespace Drupal\commerce_variation_bundle\Plugin\Commerce\EntityTrait;

use Drupal\commerce\Plugin\Commerce\EntityTrait\EntityTraitBase;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\entity\BundleFieldDefinition;

/**
 * Provides the "purchasable_entity_variation_bundle" trait.
 *
 * @CommerceEntityTrait(
 *   id = "purchasable_entity_variation_bundle",
 *   label = @Translation("Variation bundles"),
 *   entity_types = {"commerce_product_variation"}
 * )
 */
class VariationBundleTrait extends EntityTraitBase {

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions() {
    $fields = [];
    $fields['bundle_items'] = BundleFieldDefinition::create('entity_reference')
      ->setLabel('Bundle items')
      ->setRequired(TRUE)
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'commerce_bundle_item')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', [
        'type' => 'inline_entity_form_complex',
        'weight' => 0,
        'settings' => [
          'override_labels' => TRUE,
          'label_singular' => t('bundle item'),
          'label_plural' => t('bundle items'),
        ],
      ]);
    $fields['bundle_discount'] = BundleFieldDefinition::create('integer')
      ->setLabel(t('Bundle discount'))
      ->setDescription(t('Enter a percentage to discount the bundle item price. Use 0 to use regular price field or price lists.'))
      ->setSetting('display_description', TRUE)
      ->setSetting('max', 100)
      ->setSetting('suffix', '%')
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['bundle_split'] = BundleFieldDefinition::create('boolean')
      ->setLabel(t('Split bundle'))
      ->setDescription(t('Split bundle items into separate order items after order is placed'))
      ->setSetting('display_description', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE);

    return $fields;
  }

}
