# Commerce Variation Bundle

Sell a set of product variations as one purchasable product: a gift set, a
multi-pack, a device with its refills. The bundle is a product variation like
any other — it has a SKU, a price and a stock position — and it knows what it
holds.

## Table of contents

- Introduction
- Data model
- Requirements
- Installation
- Configuration
- Generating bundle variations in bulk
- Generated titles
- Extending: events and services
- Submodules
- Maintainers

## Introduction

- create bundles by referencing product variations and their quantities
- pricing model: by percentage / use default price field / price list module
- split bundle into separate items during order placement
- split bundle option configurable per each product variation bundle entity
- show saving amount / percentage with new adjustment type - `bundle_saving`
- integration with commerce_stock - dynamically set stock based on bundle
contents
- use product variation attributes dynamically from referenced bundle items
- generate every combination of several products' variations in one pass

## Data model

A bundle is not a new entity type. It is an ordinary product variation whose
variation type carries the `purchasable_entity_variation_bundle` trait; the
trait adds the three fields that make it a bundle. What it holds is a list of
**bundle items**, each one pointing at another product variation and saying how
many of it the bundle contains.

```
  commerce_product
        │  variations
        ▼
  commerce_product_variation ──────────── commerce_product_variation_type
  (variation type carries the                 │
   "Variation bundles" trait,                 ├─ generateTitle
   so it is a VariationBundle)                └─ third party: title_separator
        │
        │  bundle_items  (entity_reference, unlimited)
        │  bundle_discount  (int %, 0 = use the price field / price list)
        │  bundle_split     (bool, split into order items once placed)
        ▼
  commerce_bundle_item ───────────────────── commerce_bundle_item_type
        │  title     (generated or entered)      │
        │  quantity  (decimal)                   ├─ generateTitle
        │  status    (bool)                      └─ titlePattern
        │  price     (computed, read only)
        │
        │  variation  (entity_reference)
        ▼
  commerce_product_variation      ← a plain variation: the thing in the box
```

Two things follow from the shape:

- A bundle item belongs to the bundle that references it and is not shared.
  Generating or editing a bundle creates its own items.
- A bundle item has **no back-reference** to the variation holding it. That is
  why the item title pattern is configured on the bundle item type rather than
  alongside the separator on the variation type — at the moment an item builds
  its title, it cannot know which bundle will reference it.

### At order time

```
  order item (the bundle)                order item (the bundle)
        │                                        │
        │  bundle_split = FALSE                  │  bundle_split = TRUE
        ▼                                        ▼
  stays one order item              split into one order item per bundle item,
  with a bundle_saving              the bundle price distributed across them
  adjustment                        (VariationBundleSplitter)
```

The `bundle_saving` adjustment type carries the difference between the sum of
the parts and the bundle price, so a customer can see what the bundle saved.

## Requirements

This module requires Drupal Commerce Core >= 2.30

## Installation

Install the Commerce Variation Bundle module as you would normally install
any Drupal contrib module.
Visit https://www.drupal.org/node/1897420 for further information.

## Configuration

1. Navigate to /admin/commerce/config/product-types
2. Create new product and product variation type.
3. Navigate to /admin/commerce/config/product-variation-types
4. Edit newly created variation type and select under traits: Variation bundles

Now this product variation type is going to be treated as Variation Bundle
and you would see field to reference bundle items.

__Note__:
It's not recommended to use existing variation types as Variation bundle.

Bundle item types are managed at
/admin/commerce/config/bundle-types. The shipped `default` type is enough for
most sites; add more only when different kinds of bundle item need different
fields or a different generated title.

## Generating bundle variations in bulk

A bundle product with a handful of source products has more combinations than
anyone wants to enter by hand. **Generate variations**, on the variations tab
of a bundle product, builds them in one pass:

1. Add the products to combine. Bundle products are not offered — a bundle of
   bundles multiplies out the inner combinations as well, and the split,
   pricing and stock handling assume a bundle item points at a plain variation.
2. Choose which variations of each product take part, and how many of each the
   bundle holds.
3. Fill in the fields every generated variation should share — price, status,
   any custom field.
4. Generate.

One bundle variation is created per combination, each with its own bundle
items. A combination whose SKU already exists is skipped rather than
duplicated, and the form says how many were skipped.

SKUs are built from the source SKUs. Both options — including the quantities,
and prefixing the parent product ID — are on by default, because without them
the same variations bundled in different amounts collide, and a single-product
bundle collides with the variation it holds.

The number of combinations is shown before you commit to a run, and a run over
500 is refused: generate in smaller passes, or reduce it in code (see below).

## Generated titles

When a variation type generates its own titles, a bundle is named after what it
holds:

```
  1x Cleanser & 2x Serum
  └────┬────┘ │ └───┬───┘
       │      │     └──── one bundle item, formatted by the bundle item type's
       │      │           title pattern:  @quantityx @title
       │      └────────── the variation type's separator:  " & "
       └───────────────── the bundle item's own title
```

Both parts are configurable:

- **Bundle item type** (`/admin/commerce/config/bundle-types`) — the pattern one
  item is named by, with the `@quantity` and `@title` placeholders.
- **Variation type** (`/admin/commerce/config/product-variation-types`) — the
  separator placed between items.

If the variation type does *not* generate titles, the generate form can name
the variations the same way for one run, and the format can be overridden there
— nothing recomputes it afterwards. A type that *does* generate titles has them
rebuilt on every save, so a per-run override could not survive and is not
offered.

## Extending: events and services

### Deciding which combinations to generate

Generating offers the cartesian product of every source variation, which is
only a starting point. Which of those are worth selling is a question about the
catalogue, not about bundles, and the module cannot answer it — four products
of 4, 11, 10 and 3 variations offer 1320 combinations, and on a real catalogue
most of them pair sizes, pack counts or market variants that do not go
together.

Subscribe to `VariationBundleEvents::GENERATE_VARIATIONS` to cut the list down
before anything is created:

```php
public function onGenerateVariations(GenerateVariationsEvent $event): void {
  $event->setCombinations(array_filter(
    $event->getCombinations(),
    // One combination: a list of ['variation' => ..., 'quantity' => ...].
    fn(array $combination): bool => $this->isSellable($combination),
  ));
}
```

`setCombinations()` reindexes, so `array_filter()` is enough.

`VariationBundleEvents::GENERATE_VARIATION` fires once per combination with the
variation and its bundle items **built but not yet saved**, so a subscriber can
fill in field values, override the generated SKU or title, or `skip()` the
combination without leaving anything behind.

Subscribers also run when the form counts combinations, so the number shown and
the run that follows agree. Keep them cheap and free of side effects.

### Generating without the form

`commerce_variation_bundle.variation_generator` does the work, and knows
nothing about form state — call it from a drush command, a migration, a queue
worker or a UI of your own:

```php
$generator = \Drupal::service('commerce_variation_bundle.variation_generator');

$selection = [
  $product_a->id() => ['variations' => $variations_a, 'quantity' => 1],
  $product_b->id() => ['variations' => $variations_b, 'quantity' => 4],
];

// What would be created, after subscribers have had their say.
$count = count($generator->getCombinations($bundle_product, $selection));

$result = $generator->generate($bundle_product, $selection, [
  'template' => $template_variation,
  'fields' => ['price', 'status'],
  'sku' => ['include_quantities' => TRUE, 'include_product_id' => TRUE],
  'title' => ['generate' => TRUE],
]);

$result->getCreatedCount();
$result->getDuplicateCount();
$result->getSkippedCount();
```

`buildCombinations()` is the plain arithmetic, without events — useful for a
preview that should not consult subscribers. Anything reporting a number to a
user wants `getCombinations()`.

## Submodules

- **Commerce Variation Bundle Attributes** — builds a bundle variation's
  attributes from the variations it references, so a bundle can be chosen by
  the attributes of its contents.

## Maintainers

Current maintainers:
- Valentino Međimorec ([@valic](https://www.drupal.org/u/valic))
