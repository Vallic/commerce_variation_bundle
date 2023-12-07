<?php

namespace Drupal\Tests\commerce_variation_bundle\Kernel\Entity;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\commerce_variation_bundle\Entity\BundleItem;
use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;
use Drupal\Tests\commerce_product\Kernel\Entity\ProductVariationTest;

/**
 * Tests the Product bundle variation entity.
 *
 * @coversDefaultClass \Drupal\commerce_variation_bundle\Entity\VariationBundle
 *
 * @group commerce_variation_bundle
 */
class VariationBundleTest extends ProductVariationTest {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'path',
    'commerce_product',
    // Needed to confirm that url generation doesn't cause a crash when
    // deleting a product variation without a referenced product.
    'menu_link_content',
    'commerce_variation_bundle',
    'commerce_variation_bundle_test',
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
    $this->installConfig(['commerce_product', 'commerce_variation_bundle', 'commerce_variation_bundle_test']);

    $user = $this->createUser();
    $this->user = $this->reloadEntity($user);

    // Install trait.
    $variation_type = ProductVariationType::load('bundle');
    $variation_type->setTraits(['purchasable_entity_variation_bundle']);
    $variation_type->save();

    $trait = $this->container->get('plugin.manager.commerce_entity_trait')->createInstance('purchasable_entity_variation_bundle');
    $this->container->get('plugin.manager.commerce_entity_trait')->installTrait($trait, 'commerce_product_variation', 'bundle');
  }

  /**
   * @covers ::getOrderItemTypeId
   * @covers ::getOrderItemTitle
   * @covers ::getProduct
   * @covers ::getProductId
   * @covers ::getSku
   * @covers ::setSku
   * @covers ::getTitle
   * @covers ::setTitle
   * @covers ::getListPrice
   * @covers ::setListPrice
   * @covers ::getPrice
   * @covers ::setPrice
   * @covers ::isActive
   * @covers ::setActive
   * @covers ::getCreatedTime
   * @covers ::setCreatedTime
   * @covers ::getOwner
   * @covers ::setOwner
   * @covers ::getOwnerId
   * @covers ::setOwnerId
   * @covers ::getStores
   * @covers ::getBundleVariations
   * @covers ::getBundleDiscount
   * @covers ::getBundleItems
   * @covers ::getBundlePrice
   */
  public function testProductVariation() {
    // Verify we don't break Commerce core product variation.
    parent::testProductVariation();

    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = Product::create([
      'type' => 'bundle',
      'title' => 'My Product Title',
    ]);
    $product->save();
    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
    $variation = ProductVariation::create([
      'type' => 'bundle',
      'product_id' => $product->id(),
    ]);
    $variation->save();
    $variation = $this->reloadEntity($variation);

    // Check for Variation bundle specific methods.
    $this->assertTrue($variation->hasField('bundle_items'));
    $this->assertTrue($variation->hasField('bundle_discount'));
    $this->assertTrue($variation->hasField('bundle_split'));
    $this->assertInstanceOf(VariationBundleInterface::class, $variation);

    $this->assertFalse($variation->isPercentageOffer());
    $variation->set('bundle_discount', 20);
    $variation->save();
    $this->assertTrue($variation->isPercentageOffer());
    $this->assertEmpty($variation->getBundleItems());

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
      'quantity' => 2,
    ]);
    $bundle_item_2->save();

    $bundle_item_1 = $this->reloadEntity($bundle_item_1);
    $bundle_item_2 = $this->reloadEntity($bundle_item_2);

    $variation->set('bundle_items', [$bundle_item_1, $bundle_item_2]);
    $variation->save();

    $this->assertEquals(2, count($variation->getBundleItems()));
    $this->assertEquals(40.50, $variation->getBundlePrice()->getNumber());
  }

}
