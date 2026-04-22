# Maho Demand Signals

Surface what your customers want that you don't have.

A merchandising report for Maho / OpenMage. Captures four implicit demand signals and rolls them into a composite score per product and per search query:

1. **Wishlist adds** (high intent, low friction)
2. **Cart abandons** (very high intent, bounced on price / shipping / stock)
3. **Zero-result searches** (explicit unmet demand, independent of catalog)
4. **Out-of-stock product views** (interest in something you can't sell right now)

Optionally: `explicit_restock` signals when you bolt on a "Notify me when back in stock" button (the schema supports it; the UI hook is your call).

**Free, OSS (BSD-2-Clause).** No SaaS, no phone-home, no licence key. Data stays in your DB.

## What this is not

- Not a restock-alert email system. (That's a different module.)
- Not a conversion-optimisation tool. (That's analytics, and you already have one.)
- Not predictive ML. It's counting, weighting, and sorting.

## Why the distinction matters

Most "demand analytics" extensions are dashboards over `sales_order_item` -- they tell you what *sold*. That's a lagging indicator and it's already visible in every Maho report. This module aggregates the *implicit* signals that precede a sale: interest without purchase. Those are the ones merchandisers can actually act on (source new SKUs, reprice, restock, rework PDPs).

## Installation

```bash
composer require mageaustralia/maho-module-demand-signals
./maho migrate
./maho cache:flush
```

Enabled by default. Configure under **System -> Configuration -> Catalog -> Demand Signals**.

## Configuration

### General
- **Enabled:** master toggle. Off = observers no-op, aggregator cron no-op.
- **Retention (days):** raw events auto-pruned after N days (default 90). The aggregate table is permanent.

### Signals
Per-signal-type toggles. Silence a class of signals (e.g. you don't want cart-abandoned scanning because you already have an abandoned-cart email flow that tracks the same).

### Weights
Each signal type has a weight that feeds the composite score (`event_count * weight`). Defaults:

| signal | weight | rationale |
|---|---|---|
| `explicit_restock` | 10 | user manually asked -- the strongest possible signal |
| `cart_abandoned` | 5 | got all the way to checkout, bailed |
| `wishlist` | 3 | low friction but deliberate |
| `added_to_cart` | 2 | includes casual browse-sessions |
| `search_no_results` | 2 | high intent, low specificity (typo vs. real demand) |
| `oos_view` | 1 | passive, noisy |

These are plausible starting points, not empirical. Expect to recalibrate against your catalog.

### Cart
- **Abandon after (days):** how old an un-converted active cart must be before the daily sweep emits a `cart_abandoned` signal per line item. Default 7.

## Reports

Two reports under **Reports -> Demand Signals**:

### Top Demand-signal Products
Composite score (current month) per product, descending. Answers "what are customers asking for by behaviour that I'm either out of or otherwise failing to sell?".

### Unmet Search Demand
Normalised zero-result search queries, ranked. Answers "what are people typing into search that returns nothing -- and how often?". Direct input into your category tree, synonym list, or sourcing pipeline.

## How it works

```
observer -> mageaustralia_demandsignals_event (raw log)
                       |
                       v
   cron 10 * * * *  rollup (month bucket upsert)
                       |
                       v
         mageaustralia_demandsignals_aggregate
                       |
                       v
                  admin reports
```

Observers are intentionally thin: each inserts one row and returns. No payload assembly, no HTTP, nothing that could ever add measurable latency to storefront actions.

Rollup runs hourly, but every run recomputes the full current and previous month bucket from the raw log and upserts. Idempotent by construction -- re-running changes nothing. Late-arriving events (e.g. a rare cross-midnight-UTC fire) land in the right bucket on the next sweep.

### Data model

Two tables:

| table | purpose |
|---|---|
| `mageaustralia_demandsignals_event` | raw event log, time-bounded by retention |
| `mageaustralia_demandsignals_aggregate` | rolled-up per period/entity/signal, permanent |

All DDL via `Varien_Db_Ddl_Table::TYPE_*` constants. Portable across MySQL / MariaDB / PostgreSQL / SQLite. The aggregate `unique_identifier_count` uses `COUNT(DISTINCT CONCAT(...))`, which works on every supported DB.

### Idempotency

Re-running the aggregator is always safe. The rollup is a `SELECT COUNT(*) GROUP BY ... -> INSERT ON DUPLICATE KEY UPDATE` against a unique key on `(period, entity_type, entity_id, store_id, signal_type)`. No running totals, no state machine.

The cart-abandoned sweep is guarded by a `meta LIKE '%"quote_id":N%'` check before insert, so the same quote never records twice even if the cron double-fires.

## Observer surface

| Maho event | signal |
|---|---|
| `wishlist_add_product` | `wishlist` |
| `checkout_cart_product_add_after` | `added_to_cart` |
| `catalogsearch_query_save_after` (when `num_results === 0`) | `search_no_results` |
| `catalog_controller_product_view` (when stock item not in-stock) | `oos_view` |
| (daily cron sweep of `sales_quote`) | `cart_abandoned` |
| (your own observer, see `Helper::recordEvent`) | `explicit_restock` |

## Extending

Record a custom signal from your own module:

```php
Mage::helper('mageaustralia_demandsignals')->recordEvent(
    signalType: MageAustralia_DemandSignals_Helper_Data::SIGNAL_EXPLICIT_RESTOCK,
    entityType: 'product',
    entityId:   $product->getId(),
    entityKey:  $product->getSku(),
    storeId:    $storeId,
    customerId: $customerId,
    sessionId:  $sessionId,
    meta:       ['source' => 'restock_form'],
);
```

The rollup picks up new signal types automatically if they exist in config weights; any row with no matching weight falls back to 1.

## Tests

Playwright tests live in `tests/playwright/`. Run against `maho-playwright-rig`:

```bash
# From maho-playwright-rig:
./scripts/seed-module.sh /path/to/maho-module-demand-signals
./scripts/reset.sh

# From this repo:
npm install
npx playwright test
```

Coverage:

- Admin Reports menu renders both report entries.
- Storefront canary (regression guard against the 4 observers).
- End-to-end: run a no-result search on the storefront, assert a row lands in `mageaustralia_demandsignals_event` with `signal_type='search_no_results'`.

## Licence

BSD-2-Clause. Do whatever you want; no warranty. Issues and PRs welcome at https://github.com/mageaustralia/maho-module-demand-signals.
