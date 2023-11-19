<?php

namespace Drupal\commerce_variation_bundle;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler as CoreEntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides an access control handler for bundle item.
 *
 * Product bundle item are always managed in the scope of their parent
 * (the product variation), so they have a simplified permission set,
 * and rely on parent access when possible. Unless we have view operation,
 * then it's allowed.
 */
class BundleItemAccessControlHandler extends CoreEntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($account->hasPermission($this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if ($operation === 'view') {
      return AccessResult::allowed()->addCacheableDependency($entity);
    }

    return AccessResult::neutral();
  }

}
