<?php

namespace Drupal\Tests\commerce_variation_bundle\Unit;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_variation_bundle\BundleVariationGenerator;
use Drupal\commerce_variation_bundle\Event\GenerateVariationsEvent;
use Drupal\commerce_variation_bundle\Event\VariationBundleEvents;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Tests building the combinations a bundle selection produces.
 */
#[CoversClass(BundleVariationGenerator::class)]
#[Group('commerce_variation_bundle')]
class BundleVariationGeneratorTest extends UnitTestCase {

  /**
   * The event dispatcher the generator is built with.
   */
  protected EventDispatcher $eventDispatcher;

  /**
   * The generator under test.
   */
  protected BundleVariationGenerator $generator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->eventDispatcher = new EventDispatcher();
    $this->generator = new BundleVariationGenerator(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->eventDispatcher,
    );
  }

  /**
   * Tests the number of combinations a selection produces.
   */
  #[DataProvider('combinationCountProvider')]
  public function testBuildCombinationsCount(array $sizes, int $expected): void {
    $selection = [];
    foreach ($sizes as $product_id => $size) {
      $selection[$product_id] = [
        'variations' => $this->mockVariations($size, (string) $product_id),
        'quantity' => 1,
      ];
    }

    $this->assertCount($expected, $this->generator->buildCombinations($selection));
  }

  /**
   * Data for ::testBuildCombinationsCount().
   */
  public static function combinationCountProvider(): array {
    return [
      'no products' => [[], 0],
      'one product' => [[1 => 3], 3],
      'two products' => [[1 => 3, 2 => 4], 12],
      // The case the event exists for: four products, 768 combinations.
      'four products' => [[1 => 4, 2 => 8, 3 => 8, 4 => 3], 768],
      // A product contributing nothing makes the whole product empty: every
      // combination has to draw one variation from every product.
      'one product empty' => [[1 => 4, 2 => 0, 3 => 3], 0],
    ];
  }

  /**
   * Tests that each combination carries its product's quantity.
   */
  public function testBuildCombinationsCarryQuantities(): void {
    $combinations = $this->generator->buildCombinations([
      1 => ['variations' => $this->mockVariations(2, 'a'), 'quantity' => 1],
      2 => ['variations' => $this->mockVariations(1, 'b'), 'quantity' => 4],
    ]);

    $this->assertCount(2, $combinations);
    foreach ($combinations as $combination) {
      $this->assertCount(2, $combination);
      $this->assertSame(1, $combination[0]['quantity']);
      $this->assertSame(4, $combination[1]['quantity']);
      $this->assertInstanceOf(ProductVariationInterface::class, $combination[0]['variation']);
    }
  }

  /**
   * Tests that a missing quantity falls back to one.
   */
  public function testBuildCombinationsDefaultQuantity(): void {
    $combinations = $this->generator->buildCombinations([
      1 => ['variations' => $this->mockVariations(1, 'a')],
    ]);

    $this->assertSame(1, $combinations[0][0]['quantity']);
  }

  /**
   * Tests that a subscriber can remove combinations before they are created.
   */
  public function testGetCombinationsIsAlterable(): void {
    $this->eventDispatcher->addListener(
      VariationBundleEvents::GENERATE_VARIATIONS,
      static function (GenerateVariationsEvent $event): void {
        // Keep only the combinations whose first variation has an even SKU.
        $event->setCombinations(array_filter(
          $event->getCombinations(),
          static fn(array $combination): bool => (int) substr($combination[0]['variation']->getSku(), -1) % 2 === 0,
        ));
      },
    );

    $selection = [1 => ['variations' => $this->mockVariations(4, 'sku'), 'quantity' => 1]];

    $this->assertCount(4, $this->generator->buildCombinations($selection), 'The raw product is unfiltered.');
    $this->assertCount(2, $this->generator->getCombinations($this->mockProduct(), $selection, ['variation_type' => 'bundle']));
  }

  /**
   * Tests that filtering to nothing is allowed.
   */
  public function testGetCombinationsCanEmpty(): void {
    $this->eventDispatcher->addListener(
      VariationBundleEvents::GENERATE_VARIATIONS,
      static fn(GenerateVariationsEvent $event) => $event->setCombinations([]),
    );

    $this->assertSame([], $this->generator->getCombinations(
      $this->mockProduct(),
      [1 => ['variations' => $this->mockVariations(3, 'sku'), 'quantity' => 1]],
      ['variation_type' => 'bundle'],
    ));
  }

  /**
   * Tests that the event carries the context a subscriber needs.
   */
  public function testGenerateVariationsEventContext(): void {
    $product = $this->mockProduct();
    $selection = [7 => ['variations' => $this->mockVariations(2, 'sku'), 'quantity' => 3]];
    $seen = NULL;

    $this->eventDispatcher->addListener(
      VariationBundleEvents::GENERATE_VARIATIONS,
      static function (GenerateVariationsEvent $event) use (&$seen): void {
        $seen = $event;
      },
    );
    $this->generator->getCombinations($product, $selection, ['variation_type' => 'bundle']);

    $this->assertInstanceOf(GenerateVariationsEvent::class, $seen);
    $this->assertSame($product, $seen->getProduct());
    $this->assertSame('bundle', $seen->getVariationTypeId());
    $this->assertSame($selection, $seen->getSelection());
    $this->assertCount(2, $seen->getCombinations());
  }

  /**
   * Tests that setCombinations() reindexes, so array_filter() is enough.
   */
  public function testSetCombinationsReindexes(): void {
    $event = new GenerateVariationsEvent($this->mockProduct(), [['a'], ['b'], ['c']], [], 'bundle');
    $event->setCombinations([1 => ['b'], 2 => ['c']]);

    $this->assertSame([['b'], ['c']], $event->getCombinations());
  }

  /**
   * Returns mocked variations, keyed by id.
   *
   * @param int $count
   *   How many to build.
   * @param string $prefix
   *   Prefix for the generated SKUs, so combinations stay distinguishable.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface[]
   *   The variations.
   */
  protected function mockVariations(int $count, string $prefix): array {
    $variations = [];
    for ($i = 1; $i <= $count; $i++) {
      $variation = $this->createMock(ProductVariationInterface::class);
      $variation->method('id')->willReturn($i);
      $variation->method('getSku')->willReturn($prefix . $i);
      $variations[$i] = $variation;
    }
    return $variations;
  }

  /**
   * Returns a mocked bundle product.
   */
  protected function mockProduct(): ProductInterface {
    return $this->createMock(ProductInterface::class);
  }

}
