<?php

namespace Drupal\commerce_variation_bundle;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Defines a class to build a listing of product variation bundle type entities.
 *
 * @see \Drupal\commerce_variation_bundle\Entity\BundleItemType
 */
class BundleItemTypeListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['title'] = $this->t('Label');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $row['title'] = [
      'data' => Link::createFromRoute($entity->label(), 'entity.commerce_bundle_item.collection', ['commerce_bundle_item_type' => $entity->id()]),
      'class' => ['menu-label'],
    ];

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();

    $build['table']['#empty'] = $this->t(
      'No product variation bundle types available. <a href=":link">Add variation bundle item type</a>.',
      [':link' => Url::fromRoute('entity.commerce_bundle_item_type.add_form')->toString()]
    );

    return $build;
  }

}
