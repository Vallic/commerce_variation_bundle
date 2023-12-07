<?php

namespace Drupal\Tests\commerce_variation_bundle\Kernel\Entity;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_variation_bundle\Entity\BundleItem;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;

/**
 * Tests the bundle item entity.
 *
 * @coversDefaultClass \Drupal\commerce_variation_bundle\Entity\BundleItem
 *
 * @group commerce_variation_bundle
 */
class BundleItemTest extends CommerceKernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'commerce_product',
    'commerce_variation_bundle',
  ];

  /**
   * A sample user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_attribute_value');
    $this->installEntitySchema('commerce_bundle_item');
    $this->installConfig(['commerce_product', 'commerce_variation_bundle']);

    $user = $this->createUser();
    $this->user = $this->reloadEntity($user);
  }

  /**
   * @covers ::getTitle
   * @covers ::getQuantity
   * @covers ::getVariation
   * @covers ::getOwner
   * @covers ::getVariationId
   * @covers ::setQuantity
   * @covers ::getPrice
   */
  public function testBundleItem() {
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product_default */
    $product_default = Product::create([
      'type' => 'default',
      'title' => 'My Product Title',
    ]);
    $product_default->save();
    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation_default_1 */
    $variation_default_1 = ProductVariation::create([
      'type' => 'default',
      'product_id' => $product_default->id(),
      'price' => new Price('20.50', 'USD'),
    ]);
    $variation_default_1->save();

    $variation_default_2 = ProductVariation::create([
      'type' => 'default',
      'product_id' => $product_default->id(),
      'price' => new Price('10', 'USD'),
    ]);
    $variation_default_2->save();

    $variation_default_1 = $this->reloadEntity($variation_default_1);
    $variation_default_2 = $this->reloadEntity($variation_default_2);

    $bundle_item_1 = BundleItem::create([
      'bundle' => 'default',
      'variation' => $variation_default_1,
      'quantity' => 1,
    ]);
    $bundle_item_1->save();

    $bundle_item_2 = BundleItem::create([
      'bundle' => 'default',
      'variation' => $variation_default_2,
      'quantity' => 3,
    ]);
    $bundle_item_2->save();

    $bundle_item_1 = $this->reloadEntity($bundle_item_1);
    $bundle_item_2 = $this->reloadEntity($bundle_item_2);

    $this->assertEquals(1, $bundle_item_1->getQuantity());
    $this->assertEquals(3, $bundle_item_2->getQuantity());

    $bundle_item_1->setQuantity(5);
    $bundle_item_1->save();

    $this->assertEquals(5, $bundle_item_1->getQuantity());

    $this->assertEquals($variation_default_1->id(), $bundle_item_1->getVariationId());
    $this->assertEquals($variation_default_2->id(), $bundle_item_2->getVariationId());

    $this->assertEquals(0, $bundle_item_1->getOwnerId());
    $this->assertEquals(sprintf('%sx %s', (int) $bundle_item_1->getQuantity(), $variation_default_1->getTitle()), $bundle_item_1->getTitle());
    $this->assertEquals(sprintf('%sx %s', (int) $bundle_item_2->getQuantity(), $variation_default_2->getTitle()), $bundle_item_2->getTitle());

    $this->assertEquals('20.50', $bundle_item_1->getPrice()->getNumber());
    $this->assertEquals('10', $bundle_item_2->getPrice()->getNumber());
  }

}
