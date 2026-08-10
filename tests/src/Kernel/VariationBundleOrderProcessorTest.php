<?php

namespace Drupal\Tests\commerce_variation_bundle\Kernel;

use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\commerce_variation_bundle\BundleItemAmounts;
use Drupal\commerce_variation_bundle\Entity\BundleItem;
use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;
use Drupal\commerce_variation_bundle\VariationBundleOrderProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the bundle saving adjustment applied during the order refresh.
 */
#[CoversClass(VariationBundleOrderProcessor::class)]
#[Group('commerce_variation_bundle')]
#[RunTestsInSeparateProcesses]
class VariationBundleOrderProcessorTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_variation_bundle',
    'commerce_variation_bundle_test',
  ];

  /**
   * The order processor under test.
   *
   * @var \Drupal\commerce_order\OrderProcessorInterface
   */
  protected $processor;

  /**
   * A variation bundle worth 40.50 USD before any discount.
   *
   * It holds one variation of 20.50 USD and two of 10.00 USD.
   *
   * @var \Drupal\commerce_variation_bundle\Entity\VariationBundleInterface
   */
  protected VariationBundleInterface $bundle;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_product_attribute_value');
    $this->installEntitySchema('commerce_bundle_item');
    $this->installConfig([
      'commerce_variation_bundle',
      'commerce_variation_bundle_test',
    ]);

    // Attach the bundle fields to the "bundle" variation type.
    $variation_type = ProductVariationType::load('bundle');
    $variation_type->setTraits(['purchasable_entity_variation_bundle']);
    $variation_type->save();
    $trait_manager = $this->container->get('plugin.manager.commerce_entity_trait');
    $trait_manager->installTrait(
      $trait_manager->createInstance('purchasable_entity_variation_bundle'),
      'commerce_product_variation',
      'bundle'
    );

    $this->processor = $this->container->get('commerce_variation_bundle.order_processor');
    $this->bundle = $this->createBundle();
  }

  /**
   * Tests the saving applied to a fixed price bundle.
   */
  public function testFixedPriceBundleIsAdjustedDownToTheOfferPrice(): void {
    // The bundle is offered at 35.00 while its items are worth 40.50, so the
    // unit price is restored to the full price and the 5.50 difference becomes
    // a bundle saving adjustment.
    $order_item = $this->processOrderItem('35.00', '2');

    $this->assertEquals(new Price('40.50', 'USD'), $order_item->getUnitPrice());

    $adjustments = $order_item->getAdjustments();
    $this->assertCount(1, $adjustments);
    $this->assertSame('bundle_saving', $adjustments[0]->getType());
    $this->assertSame('Bundle saving', $adjustments[0]->getLabel());
    // The saving is multiplied by the ordered quantity.
    $this->assertEquals(new Price('-11.00', 'USD'), $adjustments[0]->getAmount());
    $this->assertSame((string) $this->bundle->id(), $adjustments[0]->getSourceId());
  }

  /**
   * Tests the saving applied to a percentage based bundle.
   */
  public function testPercentageBundleUsesTheConfiguredDiscount(): void {
    $this->bundle->set('bundle_discount', 20);
    $this->bundle->save();

    // For a percentage offer the resolved unit price is already the full price.
    $order_item = $this->processOrderItem('40.50', '1');

    $this->assertEquals(new Price('40.50', 'USD'), $order_item->getUnitPrice());

    $adjustments = $order_item->getAdjustments();
    $this->assertCount(1, $adjustments);
    // 20% off 40.50 is 32.40, an 8.10 saving.
    $this->assertEquals(new Price('-8.10', 'USD'), $adjustments[0]->getAmount());
    $this->assertSame('0.2', $adjustments[0]->getPercentage());
    $this->assertSame(20, $order_item->getData('bundle_discount'));
  }

  /**
   * Tests the per item amounts written to the order item.
   */
  public function testBundleItemAmountsAreStoredOnTheOrderItem(): void {
    $order_item = $this->processOrderItem('35.00', '2');

    $bundle_items = $order_item->getData('bundle_items');
    $this->assertCount(2, $bundle_items);

    $amounts = array_values($bundle_items);
    $this->assertContainsOnlyInstancesOf(BundleItemAmounts::class, $amounts);
    // 20.50 of 40.50 and 2 x 10.00 of 40.50, rounded to two decimals.
    $this->assertSame('0.51', $amounts[0]->getSplitPercentage());
    $this->assertSame('0.49', $amounts[1]->getSplitPercentage());
    $this->assertEquals(new Price('20.50', 'USD'), $amounts[0]->getPrice());
    $this->assertEquals(new Price('10.00', 'USD'), $amounts[1]->getPrice());
    $this->assertSame('1.00', $amounts[0]->getQuantity());
    $this->assertSame('2.00', $amounts[1]->getQuantity());
    $this->assertSame(0, $order_item->getData('bundle_discount'));
  }

  /**
   * Tests that a bundle with a zero unit price is skipped.
   */
  public function testFreeBundleIsSkipped(): void {
    // A gift-with-purchase bundle has no saving to represent, and dividing
    // by its zero unit price to get a split percentage would fail.
    $order_item = $this->processOrderItem('0', '1');

    $this->assertEquals(new Price('0', 'USD'), $order_item->getUnitPrice());
    $this->assertEmpty($order_item->getAdjustments());
    $this->assertNull($order_item->getData('bundle_items'));
    $this->assertNull($order_item->getData('bundle_discount'));
  }

  /**
   * Tests that a bundle sold at its full price gets no saving.
   */
  public function testBundleSoldAtItsFullPriceGetsNoAdjustment(): void {
    $order_item = $this->processOrderItem('40.50', '1');

    $this->assertEmpty($order_item->getAdjustments());
    $this->assertNull($order_item->getData('bundle_items'));
  }

  /**
   * Tests that a bundle priced above its items gets no saving.
   */
  public function testBundlePricedAboveItsItemsGetsNoAdjustment(): void {
    // A positive difference would read as a surcharge rather than a saving.
    $order_item = $this->processOrderItem('50.00', '1');

    $this->assertEmpty($order_item->getAdjustments());
    $this->assertNull($order_item->getData('bundle_items'));
  }

  /**
   * Tests that a manually overridden unit price survives the refresh.
   */
  public function testOverriddenUnitPriceIsPreserved(): void {
    $order_item = $this->buildOrderItem('35.00', '1');
    $order_item->setUnitPrice(new Price('30.00', 'USD'), TRUE);
    $order_item->save();
    $order_item = $this->process($order_item);

    // A manually overridden price must survive the refresh, and the saving is
    // measured against it.
    $this->assertEquals(new Price('30.00', 'USD'), $order_item->getUnitPrice());
    $this->assertEquals(new Price('-10.50', 'USD'), $order_item->getAdjustments()[0]->getAmount());
  }

  /**
   * Tests that an order item without a bundle is left alone.
   */
  public function testRegularVariationsAreLeftAlone(): void {
    $variation = $this->createVariation('12.00');
    $order_item = OrderItem::create([
      'type' => 'default',
      'quantity' => '1',
      'purchased_entity' => $variation,
      'unit_price' => new Price('12.00', 'USD'),
    ]);
    $order_item->save();

    $order_item = $this->process($order_item);

    $this->assertEquals(new Price('12.00', 'USD'), $order_item->getUnitPrice());
    $this->assertEmpty($order_item->getAdjustments());
  }

  /**
   * Tests that an order without items is ignored.
   */
  public function testEmptyOrderIsIgnored(): void {
    $order = $this->buildOrder([]);
    $this->processor->process($order);

    $this->assertEmpty($order->getItems());
  }

  /**
   * Builds an order item for the bundle and runs it through the processor.
   */
  protected function processOrderItem(string $unit_price, string $quantity): OrderItemInterface {
    return $this->process($this->buildOrderItem($unit_price, $quantity));
  }

  /**
   * Runs a single order item through the processor.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface
   *   The processed order item. This is not necessarily the object that was
   *   passed in: order_items is an entity reference revisions field, so
   *   Order::getItems() hands the processor freshly loaded revisions.
   */
  protected function process(OrderItemInterface $order_item): OrderItemInterface {
    $order = $this->buildOrder([$order_item]);
    $this->processor->process($order);
    $order_items = $order->getItems();

    return reset($order_items);
  }

  /**
   * Builds a saved order item referencing the bundle.
   */
  protected function buildOrderItem(string $unit_price, string $quantity): OrderItemInterface {
    $order_item = OrderItem::create([
      'type' => 'default',
      'quantity' => $quantity,
      'purchased_entity' => $this->bundle,
      'unit_price' => new Price($unit_price, 'USD'),
    ]);
    $order_item->save();

    return $order_item;
  }

  /**
   * Builds a draft order holding the given order items.
   *
   * The order is deliberately left unsaved: saving it would trigger a full
   * order refresh, which runs this processor along with every other one, and
   * these tests are about what this processor does on its own.
   */
  protected function buildOrder(array $order_items): Order {
    return Order::create([
      'type' => 'default',
      'state' => 'draft',
      'store_id' => $this->store->id(),
      'order_items' => $order_items,
    ]);
  }

  /**
   * Creates a bundle variation holding 20.50 plus two times 10.00 USD.
   */
  protected function createBundle(): VariationBundleInterface {
    $bundle_item_1 = BundleItem::create([
      'bundle' => 'default',
      'variation' => $this->createVariation('20.50'),
      'quantity' => 1,
    ]);
    $bundle_item_1->save();

    $bundle_item_2 = BundleItem::create([
      'bundle' => 'default',
      'variation' => $this->createVariation('10.00'),
      'quantity' => 2,
    ]);
    $bundle_item_2->save();

    $product = Product::create([
      'type' => 'bundle',
      'title' => 'Bundle product',
    ]);
    $product->save();

    $bundle = ProductVariation::create([
      'type' => 'bundle',
      'sku' => 'BUNDLE-1',
      'product_id' => $product->id(),
      'bundle_items' => [$bundle_item_1, $bundle_item_2],
    ]);
    $bundle->save();

    $bundle = $this->reloadEntity($bundle);
    $this->assertInstanceOf(VariationBundleInterface::class, $bundle);

    return $bundle;
  }

  /**
   * Creates a standalone product variation at the given price.
   */
  protected function createVariation(string $price) {
    $product = Product::create([
      'type' => 'default',
      'title' => 'Product ' . $price,
    ]);
    $product->save();

    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => $this->randomMachineName(),
      'product_id' => $product->id(),
      'price' => new Price($price, 'USD'),
    ]);
    $variation->save();

    return $this->reloadEntity($variation);
  }

}
