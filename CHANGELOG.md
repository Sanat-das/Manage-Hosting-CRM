# Changelog

All notable user-facing changes to this project are documented in this file.

## [Unreleased]

### Changed

- **Quantity & Service Behaviour is the single switch.** `products.quantity_behaviour`
  (`none` / `multiple_services` / `scaling`) now controls how an ordered quantity
  is interpreted everywhere — the store cart, admin cart, and order validation.
  `none` means the product is sold as a single unit: the order form hides the
  quantity selector and locks the quantity to 1.

### Removed

- **Legacy `sell_single` flag dropped.** The `products.sell_single` column and the
  "Sell as a single unit only" checkbox are gone. Existing single-unit products
  were migrated to `quantity_behaviour = none` automatically, and the column was
  removed from the schema. No action is needed for existing data.

### Added

- **Product pricing & billing configuration** (admin product create/edit, tabbed
  Details / Pricing / Options layout): payment type (free / one-time / recurring),
  an enabled-cycle pricing matrix with live effective-monthly + savings badges,
  per-cycle promo pricing, recurring cycles limit, auto-termination / fixed term,
  prorated billing, early-renewal windows, and configurable-option pricing that
  mirrors the product's enabled billing cycles.
- **Billing engine enforcement**: recurring-cycles-limit ends recurring billing
  after the configured cycle count (the initial invoice counts as cycle 1), and
  fixed-term auto-termination runs on `billing:recurring`.
