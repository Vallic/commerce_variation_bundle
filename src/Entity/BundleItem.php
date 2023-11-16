<?php

namespace Drupal\commerce_variation_bundle\Entity;

use Drupal\commerce\Entity\CommerceContentEntityBase;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_variation_bundle\BundleItemComputedPrice;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the variation bundle item entity class.
 *
 * @ContentEntityType(
 *   id = "commerce_bundle_item",
 *   label = @Translation("Variation bundle item"),
 *   label_collection = @Translation("Variation bundle items"),
 *   label_singular = @Translation("variation bundle item"),
 *   label_plural = @Translation("variation bundle items"),
 *   label_count = @PluralTranslation(
 *     singular = "@count variation bundle item",
 *     plural = "@count variation bundle items",
 *   ),
 *   bundle_label = @Translation("Variation bundle item type"),
 *   handlers = {
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "list_builder" = "Drupal\commerce_variation_bundle\BundleItemListBuilder",
 *     "form" = {
 *       "add" = "Drupal\commerce_variation_bundle\Form\BundleItemForm",
 *       "edit" = "Drupal\commerce_variation_bundle\Form\BundleItemForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "local_task_provider" = {
 *       "default" = "Drupal\entity\Menu\DefaultEntityLocalTaskProvider",
 *     },
 *     "route_provider" = {
 *       "default" = "Drupal\commerce_variation_bundle\Routing\BundleItemHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "commerce_bundle_item",
 *   data_table = "commerce_bundle_item_field_data",
 *   translatable = TRUE,
 *   admin_permission = "administer commerce_product",
 *   entity_keys = {
 *     "id" = "id",
 *     "langcode" = "langcode",
 *     "bundle" = "bundle",
 *     "label" = "title",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *   },
 *   links = {
 *     "collection" = "/admin/commerce/config/bundle-types/{commerce_bundle_item_type}",
 *     "add-form" = "/admin/commerce/config/bundle-types/{commerce_bundle_item_type}/add",
 *     "add-page" = "/admin/commerce/config/bundle-types/add-item",
 *     "edit-form" = "/admin/commerce/config/bundle-types/{commerce_bundle_item_type}/{commerce_bundle_item}/edit",
 *     "delete-form" = "/admin/commerce/config/bundle-types/{commerce_bundle_item_type}/{commerce_bundle_item}/delete",
 *   },
 *   bundle_entity_type = "commerce_bundle_item_type",
 *   field_ui_base_route = "entity.commerce_bundle_item_type.edit_form",
 * )
 */
class BundleItem extends CommerceContentEntityBase implements BundleItemInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  protected function urlRouteParameters($rel) {
    $uri_route_parameters = parent::urlRouteParameters($rel);
    $uri_route_parameters['commerce_bundle_item_type'] = $this->bundle();
    return $uri_route_parameters;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    if (!$this->getOwnerId()) {
      // If no owner has been set explicitly, make the anonymous user the owner.
      $this->setOwnerId(0);
    }

    /** @var \Drupal\commerce_variation_bundle\Entity\BundleItemTypeInterface $bundle_item_type */
    $bundle_item_type = $this->entityTypeManager()
      ->getStorage('commerce_bundle_item_type')
      ->load($this->bundle());

    // Variation 'bundle' can be 'commerce_product_variation' in some cases:
    // Migration stub entities in sub processes, tests. In that case, this
    // variable will be NULL.
    // @see https://www.drupal.org/project/commerce/issues/3342331
    if (!$bundle_item_type instanceof BundleItemTypeInterface) {
      return;
    }

    if ($bundle_item_type->shouldGenerateTitle()) {
      $title = $this->generateTitle();
      $this->setTitle($title);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getVariation(): ProductVariationInterface {
    return $this->getTranslatedReferencedEntity('variation');
  }

  /**
   * {@inheritdoc}
   */
  public function getVariationId(): int {
    return $this->get('variation')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(): string {
    return $this->get('title')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setTitle(string $title): static {
    $this->set('title', $title);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuantity(): string {
    return (string) $this->get('quantity')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setQuantity(string $quantity): static {
    $this->set('quantity', (string) $quantity);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPrice(): Price {
    return $this->get('price')->first()->toPrice();
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setTranslatable(TRUE)
      ->setLabel(t('Title'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Status'))
      ->setDefaultValue(TRUE)
      ->setSetting('on_label', 'Enabled')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['variation'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Variation'))
      ->setDescription(t('The product variation reference.'))
      ->setSetting('display_description', TRUE)
      ->setRequired(TRUE)
      ->setSetting('target_type', 'commerce_product_variation')
      ->setSetting('handler', 'default')
      ->addConstraint('DisallowVariationBundle')
      ->setDisplayOptions('form', [
        'type' => 'commerce_entity_select',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['quantity'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Quantity'))
      ->setDescription(t('The number of purchased units.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE)
      ->setSetting('min', 0)
      ->setDefaultValue(1)
      ->setDisplayOptions('form', [
        'type' => 'commerce_quantity',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Allows to retrieve the current price for current store
    // of referenced variation.
    $fields['price'] = BaseFieldDefinition::create('commerce_price')
      ->setLabel(t('Price'))
      ->setDescription(t('Price'))
      ->setComputed(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'commerce_price_default',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE)
      ->setClass(BundleItemComputedPrice::class);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setTranslatable(TRUE)
      ->setLabel(t('Author'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setTranslatable(TRUE)
      ->setDescription(t('The time that the product variation bundle was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setTranslatable(TRUE)
      ->setDescription(t('The time that the product variation bundle was last edited.'));

    return $fields;
  }

  /**
   * Generates the variation title based on attribute values.
   *
   * @return string
   *   The generated value.
   */
  protected function generateTitle() {
    $product_variation = $this->getVariation();
    return sprintf('%sx %s', (int) $this->getQuantity(), $product_variation->getTitle());
  }

  /**
   * {@inheritdoc}
   */
  public static function bundleFieldDefinitions(EntityTypeInterface $entity_type, $bundle, array $base_field_definitions) {
    /** @var \Drupal\Core\Field\BaseFieldDefinition[] $fields */
    $fields = [];
    /** @var \Drupal\commerce_variation_bundle\Entity\BundleItemType $bundle_item_type */
    $bundle_item_type = BundleItemType::load($bundle);
    if ($bundle_item_type && $bundle_item_type->shouldGenerateTitle()) {
      $fields['title'] = clone $base_field_definitions['title'];
      $fields['title']->setRequired(FALSE);
      $fields['title']->setDisplayConfigurable('form', FALSE);
    }

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function delete() {
    // Delete referenced bundle item on variation level.
    if (!$this->isNew()) {
      $variation_storage = $this->entityTypeManager()->getStorage('commerce_product_variation');
      /** @var \Drupal\commerce_variation_bundle\Entity\VariationBundleInterface[] $variations */
      $variations = $variation_storage->loadByProperties(['bundle_items' => $this->id()]);
      foreach ($variations as $variation) {
        foreach ($variation->getBundleItems() as $key => $bundle_item) {
          if ($bundle_item->id() === $this->id()) {
            $variation->get('bundle_items')->removeItem($key);
            $variation->save();
          }
        }
      }
    }
    parent::delete();
  }

}
