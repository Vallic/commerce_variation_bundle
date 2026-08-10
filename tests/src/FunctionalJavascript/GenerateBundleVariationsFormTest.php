<?php

namespace Drupal\Tests\commerce_variation_bundle\FunctionalJavascript;

use Drupal\Tests\commerce\FunctionalJavascript\CommerceWebDriverTestBase;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the ajax behavior of the bundle variation generator.
 *
 * The form works without JavaScript, and everything reachable that way is
 * covered by the functional test of the same name. What is left here is what
 * only a browser can show: the variations arriving without a page reload and
 * the combination count following the checkboxes as they are ticked.
 */
#[Group('commerce_variation_bundle')]
#[RunTestsInSeparateProcesses]
class GenerateBundleVariationsFormTest extends CommerceWebDriverTestBase {

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
   * Tests that products and their variations arrive without a page reload.
   */
  public function testProductsAreAddedAndRemovedInPlace(): void {
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->assertSession()->pageTextContains('No products added yet.');

    $this->addProduct($this->hats);
    $this->assertSession()->pageTextContains('Hats (2 variations)');
    $this->assertSession()->pageTextContains('HAT-S - Hats');
    $this->assertSession()->pageTextNotContains('No products added yet.');
    // The variation fields are untouched: adding a product must not run their
    // validation, and must not wipe what has already been typed.
    $this->assertSession()->pageTextNotContains('Price field is required.');

    $this->addProduct($this->scarves);
    $this->assertSession()->pageTextContains('Scarves (2 variations)');
    $this->assertSession()->pageTextContains('The current selection generates 4 bundle variations.');

    $this->getSession()->getPage()
      ->findButton('remove_source_product_' . $this->hats->id())
      ->press();
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->assertSession()->pageTextNotContains('Hats (2 variations)');
    $this->assertSession()->pageTextContains('The current selection generates 2 bundle variations.');
  }

  /**
   * Tests that the combination count follows the checkboxes.
   */
  public function testCombinationCountUpdatesWithTheSelection(): void {
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->addProduct($this->hats);
    $this->addProduct($this->scarves);
    $this->assertSession()->pageTextContains('The current selection generates 4 bundle variations.');

    // 1 hat x 2 scarves.
    $this->uncheckVariation($this->hats, 'HAT-L');
    $this->assertSession()->pageTextContains('The current selection generates 2 bundle variations.');

    // 1 hat x 1 scarf.
    $this->uncheckVariation($this->scarves, 'SCARF-BLUE');
    $this->assertSession()->pageTextContains('The current selection generates 1 bundle variation.');

    // Nothing left of the hats.
    $this->uncheckVariation($this->hats, 'HAT-S');
    $this->assertSession()->pageTextContains('No combinations can be generated from the current selection.');

    // Ticking one back brings the count back up.
    $this->checkVariation($this->hats, 'HAT-L');
    $this->assertSession()->pageTextContains('The current selection generates 1 bundle variation.');
  }

  /**
   * Tests that the narrowed selection is what actually gets generated.
   */
  public function testTheCountedSelectionIsWhatGetsGenerated(): void {
    $this->drupalGet($this->generatePath($this->bundleProduct));
    $this->addProduct($this->hats);
    $this->addProduct($this->scarves);

    $this->uncheckVariation($this->hats, 'HAT-L');
    $this->assertSession()->pageTextContains('The current selection generates 2 bundle variations.');

    $page = $this->getSession()->getPage();
    $page->fillField('sources[products][' . $this->scarves->id() . '][quantity]', '2');
    $page->find('css', '#edit-sku-options summary')->click();
    $page->checkField('sku_options[include_quantities]');
    $page->fillField('price[0][number]', '55.00');
    $page->pressButton('Generate bundle variations');

    $this->assertSession()->pageTextContains('Generated 2 bundle variations.');

    $sku_list = array_map(
      fn($variation) => $variation->getSku(),
      $this->loadGeneratedVariations(),
    );
    sort($sku_list);
    $this->assertEquals(['HAT-S-SCARF-BLUEx2', 'HAT-S-SCARF-REDx2'], $sku_list);
  }

  /**
   * Selects a product in the autocomplete and presses "Add product".
   */
  protected function addProduct(ProductInterface $product): void {
    $this->getSession()->getPage()->fillField(
      'sources[add]',
      $product->label() . ' (' . $product->id() . ')'
    );
    $this->getSession()->getPage()->pressButton('Add product');
    $this->assertSession()->assertWaitOnAjaxRequest();
  }

  /**
   * Clears one variation and waits for the count to catch up.
   */
  protected function uncheckVariation(ProductInterface $product, string $sku): void {
    $this->getSession()->getPage()->uncheckField($this->variationCheckbox($product, $sku));
    $this->assertSession()->assertWaitOnAjaxRequest();
  }

  /**
   * Ticks one variation and waits for the count to catch up.
   */
  protected function checkVariation(ProductInterface $product, string $sku): void {
    $this->getSession()->getPage()->checkField($this->variationCheckbox($product, $sku));
    $this->assertSession()->assertWaitOnAjaxRequest();
  }

  /**
   * Returns the form element name of one variation checkbox.
   */
  protected function variationCheckbox(ProductInterface $product, string $sku): string {
    $variation = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_product_variation')
      ->loadBySku($sku);

    return sprintf('sources[products][%s][variations][%s]', $product->id(), $variation->id());
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
