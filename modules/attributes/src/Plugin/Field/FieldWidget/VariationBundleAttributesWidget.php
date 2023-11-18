<?php

namespace Drupal\commerce_variation_bundle_attributes\Plugin\Field\FieldWidget;

use Drupal\commerce_product\Plugin\Field\FieldWidget\ProductVariationAttributesWidget;
use Drupal\commerce_variation_bundle\VariationBundleTrait;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'commerce_product_variation_attributes' widget.
 *
 * @FieldWidget(
 *   id = "commerce_variation_bundle_attributes",
 *   label = @Translation("Product variation bundle attributes"),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class VariationBundleAttributesWidget extends ProductVariationAttributesWidget implements ContainerFactoryPluginInterface {

  use VariationBundleTrait;

  /**
   * The product attribute field manager.
   *
   * @var \Drupal\commerce_product\ProductAttributeFieldManagerInterface
   */
  protected $attributeFieldManager;

  /**
   * The product variation attribute mapper.
   *
   * @var \Drupal\commerce_product\ProductVariationAttributeMapperInterface
   */
  protected $variationBundleAttributeMapper;

  /**
   * The field widget manager.
   *
   * @var \Drupal\Core\Field\WidgetPluginManager
   */
  protected $fieldWidgetManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->attributeFieldManager = $container->get('commerce_product.attribute_field_manager');
    $instance->variationBundleAttributeMapper = $container->get('commerce_variation_bundle_attributes.variation_attribute_mapper');
    $instance->fieldWidgetManager = $container->get('plugin.manager.field.widget');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $form_state->get('product');

    $variations = $this->loadEnabledVariations($product);

    // If we have any non-bundle variations, just use regular widget.
    // If we have bundle variations which uses regular attributes,
    // use Commerce core widget.
    if ($this->useDefaultAttributes($variations)) {
      return parent::formElement($items, $delta, $element, $form, $form_state);
    }

    if (count($variations) === 1) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $selected_variation */
      $selected_variation = reset($variations);
      // If there is 1 variation but there are attribute fields, then the
      // customer should still see the attribute widgets, to know what they're
      // buying (e.g a product only available in the Small size).
      if (empty($this->attributeFieldManager->getFieldDefinitions($selected_variation->bundle()))) {
        $element['variation'] = [
          '#type' => 'value',
          '#value' => $selected_variation->id(),
        ];
        return $element;
      }
    }

    // Build the full attribute form.
    $wrapper_id = Html::getUniqueId('commerce-product-add-to-cart-form');
    $form += [
      '#wrapper_id' => $wrapper_id,
      '#prefix' => '<div id="' . $wrapper_id . '">',
      '#suffix' => '</div>',
      '#attached' => [
        'library' => [
          'commerce_product/update_product_url',
        ],
      ],
    ];

    // If an operation caused the form to rebuild, select the variation from
    // the user's current input.
    $selected_variation = NULL;
    if ($form_state->isRebuilding()) {
      $parents = array_merge($element['#field_parents'], [$items->getName(), $delta, 'attributes']);
      $attribute_values = (array) NestedArray::getValue($form_state->getUserInput(), $parents);
      $selected_variation = $this->variationBundleAttributeMapper->selectVariation($variations, $attribute_values);
    }
    // Otherwise fallback to the default.
    if (!$selected_variation) {
      /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
      $order_item = $items->getEntity();
      if ($order_item->isNew()) {
        $selected_variation = $this->getDefaultVariation($product, $variations);
      }
      else {
        $selected_variation = $order_item->getPurchasedEntity();
      }
    }

    $element['variation'] = [
      '#type' => 'value',
      '#value' => $selected_variation->id(),
    ];
    // Set the selected variation in the form state for our AJAX callback.
    $form_state->set('selected_variation', $selected_variation->id());

    $element['attributes'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['attribute-widgets'],
      ],
    ];

    $attributes = [];
    foreach ($variations as $variation) {
      $attributes += $this->variationBundleAttributeMapper->prepareAttributes($variation, $variations);
    }

    foreach ($attributes as $field_name => $attribute) {
      $attribute_element = [
        '#type' => $attribute->getElementType(),
        '#title' => $attribute->getLabel(),
        '#options' => $attribute->getValues(),
        '#required' => $attribute->isRequired(),
        '#default_value' => $this->variationBundleAttributeMapper->getAttributeValueId($selected_variation, $field_name),
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => [get_class($this), 'ajaxRefresh'],
          'wrapper' => $form['#wrapper_id'],
          // Prevent a jump to the top of the page.
          'disable-refocus' => TRUE,
        ],
      ];
      // Convert the _none option into #empty_value.
      if (isset($attribute_element['#options']['_none'])) {
        if (!$attribute_element['#required']) {
          $attribute_element['#empty_value'] = '';
        }
        unset($attribute_element['#options']['_none']);
      }
      // Optimize the UX of optional attributes:
      // - Hide attributes that have no values.
      // - Require attributes that have a value on each variation.
      if (empty($attribute_element['#options'])) {
        $attribute_element['#access'] = FALSE;
      }

      $element['attributes'][$field_name] = $attribute_element;
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $form_state->get('product');
    $variations = $this->loadEnabledVariations($product);
    // If we have any non-bundle variations, just use regular widget.
    // If we have bundle variations which uses regular attributes,
    // use Commerce core widget.
    if ($this->useDefaultAttributes($variations)) {
      return parent::massageFormValues($values, $form, $form_state);
    }

    $default_variation = $product->getDefaultVariation();

    $output = [];
    foreach ($values as &$value) {
      $selected_variation = $this->variationBundleAttributeMapper->selectVariation($variations, $value['attributes'] ?? []);
      if ($selected_variation) {
        $value['variation'] = $selected_variation->id();
      }
      else {
        $value['variation'] = $default_variation->id();
      }
      $output[] = [
        'target_id' => $value['variation'],
      ];
    }

    return $output;
  }

}
