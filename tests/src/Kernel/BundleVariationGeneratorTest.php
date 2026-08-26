<?php

namespace Drupal\Tests\commerce_variation_bundle\Kernel;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\commerce_variation_bundle\BundleVariationGenerator;
use Drupal\commerce_variation_bundle\Entity\BundleItemType;
use Drupal\commerce_variation_bundle\Entity\VariationBundleInterface;
use Drupal\commerce_variation_bundle\Event\GenerateVariationEvent;
use Drupal\commerce_variation_bundle\Event\GenerateVariationsEvent;
use Drupal\commerce_variation_bundle\Event\VariationBundleEvents;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests generating bundle variations from a selection.
 */
#[CoversClass(BundleVariationGenerator::class)]
#[Group('commerce_variation_bundle')]
#[RunTestsInSeparateProcesses]
class BundleVariationGeneratorTest extends CommerceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'path',
    'commerce_product',
    'commerce_variation_bundle',
    'commerce_variation_bundle_test',
  ];

  /**
   * The generator under test.
   *
   * @var \Drupal\commerce_variation_bundle\BundleVariationGeneratorInterface
   */
  protected $generator;

  /**
   * The bundle product the variations are generated for.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected $bundleProduct;

  /**
   * The source variations, keyed by source product id.
   */
  protected array $sources = [];

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

    $variation_type = ProductVariationType::load('bundle');
    $variation_type->setTraits(['purchasable_entity_variation_bundle']);
    $variation_type->save();
    $trait_manager = $this->container->get('plugin.manager.commerce_entity_trait');
    $trait_manager->installTrait($trait_manager->createInstance('purchasable_entity_variation_bundle'), 'commerce_product_variation', 'bundle');

    $this->generator = $this->container->get('commerce_variation_bundle.variation_generator');

    $this->bundleProduct = Product::create(['type' => 'bundle', 'title' => 'Gift set']);
    $this->bundleProduct->save();

    // Two source products: 2 and 3 variations, so 6 combinations.
    $this->sources = [
      $this->createSource('A', 2),
      $this->createSource('B', 3),
    ];
  }

  /**
   * Tests that a selection generates one variation per combination.
   */
  public function testGenerate(): void {
    $result = $this->generator->generate($this->bundleProduct, $this->selection());

    $this->assertSame(6, $result->getCreatedCount());
    $this->assertSame(0, $result->getDuplicateCount());
    $this->assertSame(0, $result->getSkippedCount());
    $this->assertCount(6, $this->bundleProduct->getVariations());

    foreach ($result->getCreated() as $variation) {
      $this->assertSame('bundle', $variation->bundle());
      $this->assertCount(2, $variation->get('bundle_items')->referencedEntities());
      $this->assertNotEmpty($variation->getSku());
    }
  }

  /**
   * Tests that the variation type is derived when it is not given.
   */
  public function testGenerateDerivesVariationType(): void {
    $result = $this->generator->generate($this->bundleProduct, $this->selection());
    $this->assertSame('bundle', $result->getCreated()[0]->bundle());
  }

  /**
   * Tests that generating twice reports the second run as duplicates.
   */
  public function testGenerateSkipsDuplicates(): void {
    $this->generator->generate($this->bundleProduct, $this->selection());
    $second = $this->generator->generate($this->bundleProduct, $this->selection());

    $this->assertSame(0, $second->getCreatedCount());
    $this->assertSame(6, $second->getDuplicateCount());
  }

  /**
   * Tests that a subscriber can cut the combinations down.
   */
  public function testSubscriberRemovesCombinations(): void {
    $this->container->get('event_dispatcher')->addListener(
      VariationBundleEvents::GENERATE_VARIATIONS,
      static function (GenerateVariationsEvent $event): void {
        $event->setCombinations(array_slice($event->getCombinations(), 0, 2));
      },
    );

    $result = $this->generator->generate($this->bundleProduct, $this->selection());

    $this->assertSame(2, $result->getCreatedCount());
    // Combinations removed before generation are not reported as skipped -
    // they never reached it.
    $this->assertSame(0, $result->getSkippedCount());
    $this->assertCount(2, $this->bundleProduct->getVariations());
  }

  /**
   * Tests that a subscriber can fill in data before the variation is saved.
   */
  public function testSubscriberFillsData(): void {
    $this->container->get('event_dispatcher')->addListener(
      VariationBundleEvents::GENERATE_VARIATION,
      static function (GenerateVariationEvent $event): void {
        $event->getVariation()->setPrice(new Price('42', 'USD'));
      },
    );

    $result = $this->generator->generate($this->bundleProduct, $this->selection());

    foreach ($result->getCreated() as $variation) {
      $this->assertEquals(new Price('42', 'USD'), $variation->getPrice());
    }
  }

  /**
   * Tests that skipping leaves neither a variation nor its bundle items.
   */
  public function testSubscriberSkipsWithoutOrphans(): void {
    $this->container->get('event_dispatcher')->addListener(
      VariationBundleEvents::GENERATE_VARIATION,
      static function (GenerateVariationEvent $event): void {
        $event->skip();
      },
    );

    $result = $this->generator->generate($this->bundleProduct, $this->selection());

    $this->assertSame(0, $result->getCreatedCount());
    $this->assertSame(6, $result->getSkippedCount());
    $this->assertCount(0, $this->bundleProduct->getVariations());
    // The bundle items were built but never saved.
    $this->assertEmpty($this->container->get('entity_type.manager')->getStorage('commerce_bundle_item')->loadMultiple());
  }

  /**
   * Tests that a type generating its own titles keeps them.
   *
   * VariationBundle::generateTitle() already names a bundle after its items,
   * and the variation type recomputes it on save, so the option must not fight
   * it - anything set here would be overwritten.
   */
  public function testGeneratedTitleDefersToVariationType(): void {
    $this->assertTrue(ProductVariationType::load('bundle')->shouldGenerateTitle());

    $result = $this->generator->generate($this->bundleProduct, $this->selection(4), [
      'title' => ['generate' => TRUE],
    ]);

    // The entity's own generation wins, joining bundle item titles with the
    // variation type's separator.
    $this->assertStringContainsString(
      VariationBundleInterface::DEFAULT_TITLE_SEPARATOR,
      $result->getCreated()[0]->getTitle(),
    );
  }

  /**
   * Tests generating the title from the bundle contents.
   */
  public function testGeneratedTitle(): void {
    // Only meaningful when the type does not generate titles for itself.
    $variation_type = ProductVariationType::load('bundle');
    $variation_type->setGenerateTitle(FALSE);
    $variation_type->save();

    $result = $this->generator->generate($this->bundleProduct, $this->selection(4), [
      'title' => ['generate' => TRUE],
    ]);

    // Formatted by the variation type's separator and the bundle item type's
    // pattern, so it matches what the entity's own generateTitle() would
    // produce for a type that generates titles.
    $title = $result->getCreated()[0]->getTitle();
    $this->assertStringContainsString(VariationBundleInterface::DEFAULT_TITLE_SEPARATOR, $title);
    $this->assertStringContainsString('4x ', $title);
  }

  /**
   * Tests that the title is left alone when generation is off.
   */
  public function testTitleNotGeneratedByDefault(): void {
    $variation_type = ProductVariationType::load('bundle');
    $variation_type->setGenerateTitle(FALSE);
    $variation_type->save();

    $template = ProductVariation::create(['type' => 'bundle', 'title' => 'Template title']);
    $result = $this->generator->generate($this->bundleProduct, $this->selection(), [
      'template' => $template,
      'fields' => ['title'],
    ]);

    $this->assertSame('Template title', $result->getCreated()[0]->getTitle());
  }

  /**
   * Tests that quantities reach the SKU only when asked for.
   */
  public function testSkuOptions(): void {
    $result = $this->generator->generate($this->bundleProduct, $this->selection(4), [
      'sku' => ['include_quantities' => TRUE, 'include_product_id' => TRUE, 'separator' => '-'],
    ]);

    $sku = $result->getCreated()[0]->getSku();
    $this->assertStringStartsWith($this->bundleProduct->id() . '-', $sku);
    $this->assertStringContainsString('x4', $sku);
  }

  /**
   * Tests that bundle products are not usable as sources.
   */
  public function testIsBundleProduct(): void {
    $this->assertTrue($this->generator->isBundleProduct($this->bundleProduct));
    $this->assertNotContains('bundle', $this->generator->getSourceProductTypeIds());
  }

  /**
   * Tests that the per-type title settings are honoured.
   */
  public function testPerTypeTitleSettings(): void {
    $variation_type = ProductVariationType::load('bundle');
    $variation_type->setGenerateTitle(FALSE);
    $variation_type->setThirdPartySetting('commerce_variation_bundle', 'title_separator', ' + ');
    $variation_type->save();

    $item_type = BundleItemType::load('default');
    $item_type->setTitlePattern('@quantity x @title');
    $item_type->save();

    $result = $this->generator->generate($this->bundleProduct, $this->selection(4), [
      'title' => ['generate' => TRUE],
    ]);

    $title = $result->getCreated()[0]->getTitle();
    $this->assertStringContainsString(' + ', $title);
    $this->assertStringContainsString('4 x ', $title);
  }

  /**
   * Tests that a per-run override beats the configured format.
   *
   * Only meaningful for a type that does not generate titles: anything else
   * has its title recomputed from the type on every save.
   */
  public function testTitleOverridePerRun(): void {
    $variation_type = ProductVariationType::load('bundle');
    $variation_type->setGenerateTitle(FALSE);
    $variation_type->setThirdPartySetting('commerce_variation_bundle', 'title_separator', ' & ');
    $variation_type->save();

    $result = $this->generator->generate($this->bundleProduct, $this->selection(4), [
      'title' => [
        'generate' => TRUE,
        'item_pattern' => '@title (x@quantity)',
        'separator' => ' | ',
      ],
    ]);

    $title = $result->getCreated()[0]->getTitle();
    $this->assertStringContainsString(' | ', $title);
    $this->assertStringContainsString('(x4)', $title);
    $this->assertStringNotContainsString(' & ', $title);
  }

  /**
   * Builds the selection used by the tests.
   *
   * @param int $second_quantity
   *   The quantity of the second source product.
   *
   * @return array
   *   The selection.
   */
  protected function selection(int $second_quantity = 1): array {
    $selection = [];
    foreach ($this->sources as $index => $variations) {
      $selection[$index] = [
        'variations' => $variations,
        'quantity' => $index === 1 ? $second_quantity : 1,
      ];
    }
    return $selection;
  }

  /**
   * Creates a source product with the given number of variations.
   *
   * @param string $prefix
   *   SKU prefix.
   * @param int $count
   *   How many variations to create.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface[]
   *   The variations, keyed by id.
   */
  protected function createSource(string $prefix, int $count): array {
    $product = Product::create(['type' => 'default', 'title' => 'Source ' . $prefix]);
    $product->save();

    $variations = [];
    for ($i = 1; $i <= $count; $i++) {
      $variation = ProductVariation::create([
        'type' => 'default',
        'sku' => $prefix . $i,
        'title' => 'Source ' . $prefix . ' ' . $i,
        'price' => new Price('10', 'USD'),
        'status' => 1,
      ]);
      $variation->save();
      $product->addVariation($variation);
      $variations[$variation->id()] = $variation;
    }
    $product->save();

    return $variations;
  }

}
