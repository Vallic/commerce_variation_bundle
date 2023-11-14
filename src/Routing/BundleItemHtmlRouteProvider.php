<?php

namespace Drupal\commerce_variation_bundle\Routing;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;

/**
 * Provides HTML routes for entities with administrative pages.
 */
class BundleItemHtmlRouteProvider extends AdminHtmlRouteProvider {

  /**
   * {@inheritdoc}
   */
  protected function getCanonicalRoute(EntityTypeInterface $entity_type) {
    return $this->getEditFormRoute($entity_type);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditFormRoute(EntityTypeInterface $entity_type) {
    $route = parent::getEditFormRoute($entity_type);
    $route
      ->setOption('parameters', [
        'commerce_bundle_item_type' => [
          'type' => 'entity:commerce_bundle_item_type',
        ],
        'commerce_bundle_item' => [
          'type' => 'entity:commerce_bundle_item',
        ],
      ])
      ->setOption('_admin_route', TRUE);

    return $route;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDeleteFormRoute(EntityTypeInterface $entity_type) {
    $route = parent::getDeleteFormRoute($entity_type);
    $route
      ->setOption('parameters', [
        'commerce_bundle_item_type' => [
          'type' => 'entity:commerce_bundle_item_type',
        ],
        'commerce_bundle_item' => [
          'type' => 'entity:commerce_bundle_item',
        ],
      ])
      ->setOption('_admin_route', TRUE);

    return $route;
  }

}
