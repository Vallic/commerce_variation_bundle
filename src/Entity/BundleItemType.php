<?php

namespace Drupal\commerce_variation_bundle\Entity;

use Drupal\commerce\Entity\CommerceBundleEntityBase;
use Drupal\Core\Entity\Annotation\ConfigEntityType;

/**
 * Defines the Variation Bundle Item type configuration entity.
 *
 * @ConfigEntityType(
 *   id = "commerce_bundle_item_type",
 *   label = @Translation("Variation Bundle type"),
 *   label_collection = @Translation("Variation Bundle types"),
 *   label_singular = @Translation("variation bundle type"),
 *   label_plural = @Translation("variation bundle types"),
 *   label_count = @PluralTranslation(
 *     singular = "@count ariation bundle item type",
 *     plural = "@count ariation bundle item types",
 *   ),
 *   handlers = {
 *     "form" = {
 *       "add" = "Drupal\commerce_variation_bundle\Form\BundleItemTypeForm",
 *       "edit" = "Drupal\commerce_variation_bundle\Form\BundleItemTypeForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *     "list_builder" = "Drupal\commerce_variation_bundle\BundleItemTypeListBuilder",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     }
 *   },
 *   admin_permission = "administer commerce_bundle_item_type",
 *   bundle_of = "commerce_bundle_item",
 *   config_prefix = "commerce_bundle_item_type",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   links = {
 *     "add-form" = "/admin/commerce/config/bundle-types/add",
 *     "edit-form" = "/admin/commerce/config/bundle-types/{commerce_bundle_item_type}/edit",
 *     "delete-form" = "/admin/commerce/config/bundle-types/{commerce_bundle_item_type}/delete",
 *     "collection" = "/admin/commerce/config/bundle-types",
 *     "canonical" = "/admin/commerce/config/bundle-types/{commerce_bundle_item_type}"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "generateTitle",
 *     "uuid",
 *   }
 * )
 */
class BundleItemType extends CommerceBundleEntityBase implements BundleItemTypeInterface {

  /**
   * The machine name of this product variation bundle type.
   *
   * @var string
   */
  protected $id;

  /**
   * The human-readable name of the product variation bundle type.
   *
   * @var string
   */
  protected $label;

  /**
   * Whether the bundle item title should be automatically generated.
   *
   * @var bool
   */
  protected $generateTitle;

  /**
   * {@inheritdoc}
   */
  public function shouldGenerateTitle(): bool {
    return (bool) $this->generateTitle;
  }

  /**
   * {@inheritdoc}
   */
  public function setGenerateTitle($generate_title): static {
    $this->generateTitle = $generate_title;
    return $this;
  }

}
