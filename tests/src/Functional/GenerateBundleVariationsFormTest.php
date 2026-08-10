<?php

namespace Drupal\Tests\commerce_variation_bundle\Functional;

use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests generating bundle variations from combinations of other variations.
 *
 * The form is built out of real submit buttons rather than autocomplete change
 * events, so the whole flow works without JavaScript and can be covered here.
 * Only the summary refreshing as variations are ticked needs a browser, which
 * the FunctionalJavascript test of the same name covers.
 */
#[Group('commerce_variation_bundle')]
#[RunTestsInSeparateProcesses]
class GenerateBundleVariationsFormTest extends CommerceBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_product',
    'commerce_variation_bundle',
    'commerce_variation_bundle_test',
  ];

  /**
   * The product the bundle variations are generated for.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected ProductInterface $bundleProduct;

  /**
   * A source product with two variations, HAT-S and HAT-L.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected ProductInterface $hats;

  /**
   * A source product with two variations, SCARF-RED and SCARF-BLUE.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected ProductInterface $scarves;

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return array_merge([
      'administer commerce_product',
      'administer commerce_product_type',
      'access commerce_product overview',
    ], parent::getAdministratorPermissions());
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

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

    $this->bundleProduct = $this->createEntity('commerce_product', [
      'type' => 'bundle',
      'title' => 'Winter gift set',
      'stores' => [$this->store],
    ]);
    $this->hats = $this->createSourceProduct('Hats', ['HAT-S' => '15.00', 'HAT-L' => '17.00']);
    $this->scarves = $this->createSourceProduct('Scarves', ['SCARF-RED' => '20.00', 'SCARF-BLUE' => '20.00']);
  }

  /**
   * Tests that the form is only reachable for bundle products.
   */
  public function testFormIsLimitedToBundleProducts(): void {
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('No products added yet.');

    // The source products use the plain variation type, which has no bundle
    // trait, so there is nothing to generate for them.
    $this->drupalGet($this->generatePath($this->hats));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that adding a product loads its variations.
   */
  public function testAddingProductLoadsItsVariations(): void {
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->addProduct($this->hats);

    $this->assertSession()->pageTextContains('Hats (2 variations)');
    $this->assertSession()->pageTextContains('HAT-S - Hats');
    $this->assertSession()->pageTextContains('HAT-L - Hats');
    // Everything starts selected, so the count is the whole product.
    $this->assertSession()->pageTextContains('The current selection generates 2 bundle variations.');
    // The autocomplete is cleared so the next product can be typed right away.
    $this->assertSession()->fieldValueEquals('sources[add]', '');

    $this->addProduct($this->scarves);
    $this->assertSession()->pageTextContains('Scarves (2 variations)');
    $this->assertSession()->pageTextContains('The current selection generates 4 bundle variations.');
  }

  /**
   * Tests that removing a product drops it and its variations.
   */
  public function testRemovingProduct(): void {
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->addProduct($this->hats);
    $this->addProduct($this->scarves);
    $this->assertSession()->pageTextContains('The current selection generates 4 bundle variations.');

    $this->getSession()->getPage()
      ->findButton('remove_source_product_' . $this->hats->id())
      ->press();

    $this->assertSession()->pageTextNotContains('Hats (2 variations)');
    $this->assertSession()->pageTextContains('Scarves (2 variations)');
    $this->assertSession()->pageTextContains('The current selection generates 2 bundle variations.');
  }

  /**
   * Tests that clearing variations reduces the combinations generated.
   */
  public function testClearingVariationsReducesCombinations(): void {
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->addProduct($this->hats);
    $this->addProduct($this->scarves);

    // Drop one hat, then re-post so the summary catches up. Without
    // JavaScript the count only refreshes when the form is submitted.
    $this->uncheckVariation($this->hats, 'HAT-L');
    $this->submitForm([], 'Add product');
    $this->assertSession()->pageTextContains('The current selection generates 2 bundle variations.');

    $this->submitForm(['price[0][number]' => '30.00'], 'Generate bundle variations');
    $this->assertSession()->pageTextContains('Generated 2 bundle variations.');

    $this->assertGeneratedSkuList(['HAT-S-SCARF-RED', 'HAT-S-SCARF-BLUE']);
  }

  /**
   * Tests the default SKU, built from the child SKUs alone.
   */
  public function testGeneratesOneVariationPerCombination(): void {
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->addProduct($this->hats);
    $this->addProduct($this->scarves);

    $this->submitForm(['price[0][number]' => '30.00'], 'Generate bundle variations');
    $this->assertSession()->pageTextContains('Generated 4 bundle variations.');

    $this->assertGeneratedSkuList([
      'HAT-S-SCARF-RED',
      'HAT-S-SCARF-BLUE',
      'HAT-L-SCARF-RED',
      'HAT-L-SCARF-BLUE',
    ]);

    // Every bundle holds one of each, the form default.
    $bundle = $this->loadGeneratedVariation('HAT-S-SCARF-RED');
    $quantities = array_map(fn($item) => $item->getQuantity(), $bundle->getBundleItems());
    $this->assertEquals(['1.00', '1.00'], array_values($quantities));
    $this->assertEquals(new Price('35.00', 'USD'), $bundle->getBundlePrice());
  }

  /**
   * Tests the quantity and parent product ID SKU options.
   */
  public function testSkuOptions(): void {
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->addProduct($this->hats);
    $this->addProduct($this->scarves);
    $this->uncheckVariation($this->hats, 'HAT-L');
    $this->uncheckVariation($this->scarves, 'SCARF-BLUE');

    $this->submitForm([
      'sources[products][' . $this->scarves->id() . '][quantity]' => '3',
      'sku_options[include_quantities]' => TRUE,
      'sku_options[include_product_id]' => TRUE,
      'price[0][number]' => '70.00',
    ], 'Generate bundle variations');

    $this->assertSession()->pageTextContains('Generated 1 bundle variation.');

    // The parent product ID leads, and only the quantity above one is spelled
    // out - HAT-S is bundled once and carries no suffix.
    $sku = $this->bundleProduct->id() . '-HAT-S-SCARF-REDx3';
    $this->assertGeneratedSkuList([$sku]);

    $bundle = $this->loadGeneratedVariation($sku);
    $quantities = [];
    foreach ($bundle->getBundleItems() as $bundle_item) {
      $quantities[$bundle_item->getVariation()->getSku()] = $bundle_item->getQuantity();
    }
    $this->assertEquals(['HAT-S' => '1.00', 'SCARF-RED' => '3.00'], $quantities);
    // 15.00 + 3 x 20.00.
    $this->assertEquals(new Price('75.00', 'USD'), $bundle->getBundlePrice());
  }

  /**
   * Tests that a repeated combination is skipped rather than duplicated.
   */
  public function testDuplicateSkuIsSkipped(): void {
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->addProduct($this->hats);
    $this->addProduct($this->scarves);
    $this->submitForm(['price[0][number]' => '30.00'], 'Generate bundle variations');
    $this->assertSession()->pageTextContains('Generated 4 bundle variations.');

    // The same combinations again, at a different quantity but without the
    // quantity in the SKU, collide with what is already there.
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->addProduct($this->hats);
    $this->addProduct($this->scarves);
    $this->submitForm([
      'sources[products][' . $this->scarves->id() . '][quantity]' => '2',
      'price[0][number]' => '30.00',
    ], 'Generate bundle variations');
    $this->assertSession()->pageTextContains('Skipped 4 variations with duplicate SKUs.');
    $this->assertSession()->pageTextContains('Include the quantities');
    $this->assertCount(4, $this->loadGeneratedVariations());

    // Spelling the quantity out makes room for the second set.
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->addProduct($this->hats);
    $this->addProduct($this->scarves);
    $this->submitForm([
      'sources[products][' . $this->scarves->id() . '][quantity]' => '2',
      'sku_options[include_quantities]' => TRUE,
      'price[0][number]' => '30.00',
    ], 'Generate bundle variations');
    $this->assertSession()->pageTextContains('Generated 4 bundle variations.');

    $this->assertGeneratedSkuList([
      'HAT-S-SCARF-RED',
      'HAT-S-SCARF-BLUE',
      'HAT-L-SCARF-RED',
      'HAT-L-SCARF-BLUE',
      'HAT-S-SCARF-REDx2',
      'HAT-S-SCARF-BLUEx2',
      'HAT-L-SCARF-REDx2',
      'HAT-L-SCARF-BLUEx2',
    ]);
  }

  /**
   * Tests a bundle built from a single product, such as a multi-pack.
   */
  public function testSingleProductBundleNeedsSkuOption(): void {
    // With one source product and a quantity of one, the generated SKU is the
    // child SKU verbatim, so it always collides with the child variation.
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->addProduct($this->hats);
    $this->submitForm(['price[0][number]' => '30.00'], 'Generate bundle variations');

    $this->assertSession()->pageTextContains('Skipped 2 variations with duplicate SKUs.');
    $this->assertEmpty($this->loadGeneratedVariations());

    // Either SKU option keeps the bundle apart from the variation it holds.
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->addProduct($this->hats);
    $this->submitForm([
      'sources[products][' . $this->hats->id() . '][quantity]' => '3',
      'sku_options[include_quantities]' => TRUE,
      'price[0][number]' => '40.00',
    ], 'Generate bundle variations');

    $this->assertSession()->pageTextContains('Generated 2 bundle variations.');
    $this->assertGeneratedSkuList(['HAT-Sx3', 'HAT-Lx3']);

    $bundle = $this->loadGeneratedVariation('HAT-Sx3');
    $this->assertEquals(new Price('45.00', 'USD'), $bundle->getBundlePrice());
  }

  /**
   * Tests that a bundle product cannot be combined with itself.
   */
  public function testBundleCannotBeCombinedWithItself(): void {
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->addProduct($this->bundleProduct);

    $this->assertSession()->pageTextContains('A bundle product cannot be combined with itself.');
    $this->assertSession()->pageTextContains('No products added yet.');
  }

  /**
   * Tests that adding the same product twice adds it once.
   */
  public function testAddingTheSameProductTwice(): void {
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->addProduct($this->hats);
    $this->addProduct($this->hats);

    $this->assertSession()->pageTextContains('The current selection generates 2 bundle variations.');
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', '[name^="remove_source_product_"]'));
  }

  /**
   * Tests the validation that runs when generating.
   */
  public function testValidation(): void {
    $this->drupalGet($this->generatePath($this->bundleProduct));

    $this->submitForm(['price[0][number]' => '30.00'], 'Generate bundle variations');
    $this->assertSession()->pageTextContains('Add at least one product to combine.');

    $this->addProduct($this->hats);
    $this->uncheckVariation($this->hats, 'HAT-S');
    $this->uncheckVariation($this->hats, 'HAT-L');
    $this->submitForm(['price[0][number]' => '30.00'], 'Generate bundle variations');
    $this->assertSession()->pageTextContains('Select at least one variation of Hats, or remove the product.');

    // The variation fields are still validated as well.
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->addProduct($this->hats);
    $this->submitForm([], 'Generate bundle variations');
    $this->assertSession()->pageTextContains('Price field is required.');

    $this->assertEmpty($this->loadGeneratedVariations());
  }

  /**
   * Tests that adding a product does not validate the variation fields.
   */
  public function testAddingProductSkipsVariationValidation(): void {
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->addProduct($this->hats);

    // Price is required but empty at this point, and must not get in the way.
    $this->assertSession()->pageTextNotContains('Price field is required.');
    $this->assertSession()->pageTextContains('Hats (2 variations)');
  }

  /**
   * Fills the autocomplete with a product and presses "Add product".
   */
  protected function addProduct(ProductInterface $product): void {
    $this->submitForm([
      'sources[add]' => $product->label() . ' (' . $product->id() . ')',
    ], 'Add product');
  }

  /**
   * Clears one variation of a source product.
   */
  protected function uncheckVariation(ProductInterface $product, string $sku): void {
    $variation = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_product_variation')
      ->loadBySku($sku);

    $this->getSession()->getPage()->uncheckField(
      sprintf('sources[products][%s][variations][%s]', $product->id(), $variation->id())
    );
  }

  /**
   * Asserts exactly which bundle variations the product now holds.
   *
   * @param string[] $expected_sku_list
   *   The expected SKUs, in any order.
   */
  protected function assertGeneratedSkuList(array $expected_sku_list): void {
    $actual = array_map(
      fn($variation) => $variation->getSku(),
      $this->loadGeneratedVariations(),
    );

    sort($expected_sku_list);
    sort($actual);
    $this->assertEquals($expected_sku_list, $actual);
  }

  /**
   * Returns the variations of the bundle product.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface[]
   *   The variations.
   */
  protected function loadGeneratedVariations(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_product');
    $storage->resetCache([$this->bundleProduct->id()]);

    return $storage->load($this->bundleProduct->id())->getVariations();
  }

  /**
   * Loads one generated bundle variation by SKU.
   *
   * @return \Drupal\commerce_variation_bundle\Entity\VariationBundleInterface
   *   The bundle variation.
   */
  protected function loadGeneratedVariation(string $sku) {
    foreach ($this->loadGeneratedVariations() as $variation) {
      if ($variation->getSku() === $sku) {
        return $variation;
      }
    }

    $this->fail(sprintf('No generated variation with SKU "%s".', $sku));
  }

  /**
   * Creates a product with one variation per given SKU.
   *
   * @param string $title
   *   The product title.
   * @param string[] $variations
   *   Price keyed by SKU.
   */
  protected function createSourceProduct(string $title, array $variations): ProductInterface {
    $product = $this->createEntity('commerce_product', [
      'type' => 'default',
      'title' => $title,
      'stores' => [$this->store],
    ]);

    foreach ($variations as $sku => $price) {
      $variation = $this->createEntity('commerce_product_variation', [
        'type' => 'default',
        'sku' => $sku,
        'title' => $title,
        'price' => new Price($price, 'USD'),
        'status' => 1,
      ]);
      $product->addVariation($variation);
    }
    $product->save();

    return $this->reloadEntity($product);
  }

  /**
   * Returns the path of the generate form for a product.
   */
  protected function generatePath(ProductInterface $product): string {
    return 'product/' . $product->id() . '/variations/generate-bundles';
  }

}
