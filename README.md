# Commerce Variation Bundle

## Table of contents

- Introduction
- Requirements
- Installation
- Configuration
- Maintainers

## Introduction

The Commerce Variation Bundle allows you to create groups / bundles of product
variations.

- create bundles by referencing product variations and their quantities
- pricing model: by percentage / use default price field / pricelist module
- split bundle into separate items during order placement
- split bundle option configurable per each product variation bundle entity
- show saving amount / percentage with new adjustment type - `bundle_saving`
- integration with commerce_stock - dynamically set stock based on bundle
contents
- use product variation attributes dynamically from referenced bundle items

## Requirements

This module requires Drupal Commerce Core >= 2.30

## Installation

Install the Commerce Exchanger module as you would normally install
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


## Maintainers

Current maintainers:
- Valentino Međimorec ([@valic](https://www.drupal.org/u/valic))
