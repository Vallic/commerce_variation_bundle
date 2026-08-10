<?php

namespace Drupal\commerce_variation_bundle\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Grants access only when the product's variation type has the bundle trait.
 */
final class BundleVariationGenerateAccess implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Checks access for the generate bundle variations route.
   */
  public function access(AccountInterface $account, ProductInterface $commerce_product): AccessResultInterface {
    if (!$account->hasPermission('administer commerce_product')) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    $product_type = $this->entityTypeManager
      ->getStorage('commerce_product_type')
      ->load($commerce_product->bundle());

    if (!$product_type) {
      return AccessResult::forbidden()->addCacheableDependency($commerce_product);
    }

    foreach ($product_type->getVariationTypeIds() as $variation_type_id) {
      $variation_type = $this->entityTypeManager
        ->getStorage('commerce_product_variation_type')
        ->load($variation_type_id);

      if ($variation_type && $variation_type->hasTrait('purchasable_entity_variation_bundle')) {
        return AccessResult::allowed()
          ->cachePerPermissions()
          ->addCacheableDependency($commerce_product)
          ->addCacheableDependency($product_type)
          ->addCacheableDependency($variation_type);
      }
    }

    return AccessResult::forbidden()
      ->addCacheableDependency($commerce_product)
      ->addCacheableDependency($product_type);
  }

}
