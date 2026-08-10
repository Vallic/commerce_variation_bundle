<?php

namespace Drupal\Tests\commerce_variation_bundle\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\AdjustmentTypeManager;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_variation_bundle\BundleItemAmounts;
use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;
use Drupal\commerce_variation_bundle\VariationBundleSplitter;

/**
 * Tests splitting order item adjustments across the items of a bundle.
 *
 * @coversDefaultClass \Drupal\commerce_variation_bundle\VariationBundleSplitter
 *
 * @group commerce_variation_bundle
 */
class VariationBundleSplitterTest extends UnitTestCase {

  /**
   * The splitter under test.
   *
   * @var \Drupal\commerce_variation_bundle\VariationBundleSplitter
   */
  protected VariationBundleSplitter $splitter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Adjustment::__construct() validates the type against the adjustment type
    // plugin manager, so the container has to provide one.
    $adjustment_type_manager = $this->createMock(AdjustmentTypeManager::class);
    $adjustment_type_manager->method('getDefinitions')->willReturn([
      'promotion' => ['id' => 'promotion', 'label' => 'Promotion'],
      'tax' => ['id' => 'tax', 'label' => 'Tax'],
    ]);

    $container = new ContainerBuilder();
    $container->set('plugin.manager.commerce_adjustment_type', $adjustment_type_manager);
    \Drupal::setContainer($container);

    $this->splitter = new VariationBundleSplitter($this->createMock(EntityTypeManagerInterface::class));
  }

  /**
   * @covers ::split
   */
  public function testSplitIgnoresNonBundlePurchasedEntities(): void {
    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getPurchasedEntity')
      ->willReturn($this->createMock(ProductVariationInterface::class));
    $order_item->expects($this->never())->method('getAdjustments');

    $this->assertSame([], $this->splitter->split($order_item));
  }

  /**
   * @covers ::split
   */
  public function testSplitIgnoresOrderItemsWithoutBundleData(): void {
    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getPurchasedEntity')
      ->willReturn($this->createMock(VariationBundleInterface::class));
    $order_item->method('getData')->with('bundle_items')->willReturn([]);
    $order_item->expects($this->never())->method('getAdjustments');

    $this->assertSame([], $this->splitter->split($order_item));
  }

  /**
   * @covers ::split
   * @covers ::groupAdjustments
   * @covers ::splitAdjustments
   */
  public function testSplitDistributesAdjustmentsByPercentage(): void {
    $order_item = $this->buildOrderItem(
      ['1' => '0.50', '2' => '0.50'],
      [$this->buildAdjustment('promotion', '-10.00')],
    );

    $amounts = $this->splitter->split($order_item);

    // PHP casts the numeric bundle item ids used as array keys to integers.
    $this->assertSame([1, 2], array_keys($amounts));
    $this->assertAdjustmentAmounts(['-5.00'], $amounts['1']);
    $this->assertAdjustmentAmounts(['-5.00'], $amounts['2']);
  }

  /**
   * @covers ::split
   */
  public function testSplitFoldsRoundingRemainderIntoTheLastItem(): void {
    // Three equal items round to 0.33 each, so a straight split only accounts
    // for 9.90 of the 10.00 discount. The missing 0.10 has to land somewhere.
    $order_item = $this->buildOrderItem(
      ['1' => '0.33', '2' => '0.33', '3' => '0.33'],
      [$this->buildAdjustment('promotion', '-10.00')],
    );

    $amounts = $this->splitter->split($order_item);

    $this->assertAdjustmentAmounts(['-3.30'], $amounts['1']);
    $this->assertAdjustmentAmounts(['-3.30'], $amounts['2']);
    $this->assertAdjustmentAmounts(['-3.40'], $amounts['3']);
    $this->assertAmountEquals('-10.00', $this->sumAdjustments($amounts)->getNumber(), 'Total');
  }

  /**
   * @covers ::split
   */
  public function testSplitAppliesRemainderOnceForRepeatedAdjustmentTypes(): void {
    // Two promotions share the "promotion" type. The 0.10 remainder must be
    // folded into a single adjustment, not into each of them.
    $order_item = $this->buildOrderItem(
      ['1' => '0.33', '2' => '0.33', '3' => '0.33'],
      [
        $this->buildAdjustment('promotion', '-6.00'),
        $this->buildAdjustment('promotion', '-4.00'),
      ],
    );

    $amounts = $this->splitter->split($order_item);

    $this->assertAdjustmentAmounts(['-1.98', '-1.32'], $amounts['1']);
    $this->assertAdjustmentAmounts(['-1.98', '-1.32'], $amounts['2']);
    $this->assertAdjustmentAmounts(['-2.08', '-1.32'], $amounts['3']);
    $this->assertAmountEquals('-10.00', $this->sumAdjustments($amounts)->getNumber(), 'Total');
  }

  /**
   * @covers ::split
   */
  public function testSplitKeepsAdjustmentTypesSeparate(): void {
    $order_item = $this->buildOrderItem(
      ['1' => '0.33', '2' => '0.33', '3' => '0.33'],
      [
        $this->buildAdjustment('promotion', '-10.00'),
        $this->buildAdjustment('tax', '2.00'),
      ],
    );

    $amounts = $this->splitter->split($order_item);

    $this->assertAdjustmentAmounts(['-3.30', '0.66'], $amounts['1']);
    $this->assertAdjustmentAmounts(['-3.30', '0.66'], $amounts['2']);
    // Both types carry a remainder: -0.10 for the promotion, 0.02 for the tax.
    $this->assertAdjustmentAmounts(['-3.40', '0.68'], $amounts['3']);
    $this->assertAmountEquals('-8.00', $this->sumAdjustments($amounts)->getNumber(), 'Total');
  }

  /**
   * @covers ::split
   */
  public function testSplitLeavesAdjustmentMetadataIntact(): void {
    $order_item = $this->buildOrderItem(
      ['1' => '0.50', '2' => '0.50'],
      [$this->buildAdjustment('promotion', '-10.00')],
    );

    $adjustment = $this->splitter->split($order_item)['1']->getAdjustments()[0];

    $this->assertSame('promotion', $adjustment->getType());
    $this->assertSame('Promotion', $adjustment->getLabel());
    $this->assertSame('0.2', $adjustment->getPercentage());
    $this->assertSame('7', $adjustment->getSourceId());
  }

  /**
   * @covers ::split
   */
  public function testSplitWithoutAdjustmentsStillReturnsBundleAmounts(): void {
    $order_item = $this->buildOrderItem(['1' => '0.50', '2' => '0.50'], []);

    $amounts = $this->splitter->split($order_item);

    $this->assertCount(2, $amounts);
    $this->assertSame([], $amounts['1']->getAdjustments());
    $this->assertSame([], $amounts['2']->getAdjustments());
  }

  /**
   * Builds an order item carrying a bundle and the given adjustments.
   *
   * @param string[] $split_percentages
   *   Split percentage keyed by bundle item id.
   * @param \Drupal\commerce_order\Adjustment[] $adjustments
   *   The order item adjustments.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface
   *   The mocked order item.
   */
  protected function buildOrderItem(array $split_percentages, array $adjustments): OrderItemInterface {
    $bundle_items = [];
    foreach ($split_percentages as $bundle_item_id => $split_percentage) {
      $bundle_items[$bundle_item_id] = new BundleItemAmounts([
        'variation_id' => $bundle_item_id,
        'price' => new Price('10.00', 'USD'),
        'quantity' => '1',
        'split_percentage' => $split_percentage,
      ]);
    }

    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getPurchasedEntity')
      ->willReturn($this->createMock(VariationBundleInterface::class));
    $order_item->method('getData')->with('bundle_items')->willReturn($bundle_items);
    $order_item->method('getAdjustments')->willReturn($adjustments);

    return $order_item;
  }

  /**
   * Builds an adjustment with fixed metadata.
   */
  protected function buildAdjustment(string $type, string $amount): Adjustment {
    return new Adjustment([
      'type' => $type,
      'label' => ucfirst($type),
      'amount' => new Price($amount, 'USD'),
      'percentage' => '0.2',
      'source_id' => '7',
    ]);
  }

  /**
   * Asserts the adjustment amounts of a bundle item, in order.
   *
   * @param string[] $expected
   *   The expected amounts.
   * @param \Drupal\commerce_variation_bundle\BundleItemAmounts $amounts
   *   The bundle item amounts.
   */
  protected function assertAdjustmentAmounts(array $expected, BundleItemAmounts $amounts): void {
    $actual = array_map(
      fn (Adjustment $adjustment) => $adjustment->getAmount()->getNumber(),
      array_values($amounts->getAdjustments()),
    );
    $this->assertCount(count($expected), $actual);
    foreach ($expected as $delta => $number) {
      $this->assertAmountEquals($number, $actual[$delta], sprintf('Adjustment %d', $delta));
    }
  }

  /**
   * Asserts numeric equality, ignoring trailing zero differences.
   *
   * Calculator normalizes its results, so "-3.30" comes back as "-3.3".
   */
  protected function assertAmountEquals(string $expected, string $actual, string $message = ''): void {
    $this->assertSame(
      0,
      Calculator::compare($expected, $actual),
      sprintf('%s: expected %s, got %s.', $message ?: 'Amount', $expected, $actual),
    );
  }

  /**
   * Sums every adjustment of every bundle item.
   *
   * @param \Drupal\commerce_variation_bundle\BundleItemAmounts[] $amounts
   *   The bundle item amounts.
   *
   * @return \Drupal\commerce_price\Price
   *   The total.
   */
  protected function sumAdjustments(array $amounts): Price {
    $total = new Price('0', 'USD');
    foreach ($amounts as $bundle_item_amounts) {
      foreach ($bundle_item_amounts->getAdjustments() as $adjustment) {
        $total = $total->add($adjustment->getAmount());
      }
    }

    return $total;
  }

}
