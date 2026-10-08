# Electronics POS — Physical Database Design (v2.1)

**Status:** Implemented clean-schema baseline  
**Version:** 2.1  
**Updated:** 8 October 2026  
**Source:** Project Plan v1.0 and the nine active migrations in `core/database/migrations`

The baseline was successfully migrated to `pos-sinetec`: 112 application/framework tables plus Laravel's `migrations` table, for 113 total. The former database remains `pos-sinetec-legacy`. This document describes the design boundary; the active migrations are authoritative for exact executable columns and constraints.

## 0. Approved decisions

| Topic | Decision |
| --- | --- |
| Inventory masters | Separate `raw_materials`, `parts`, `finished_products`. |
| Stock | Separate movement and balance tables for all three domains. |
| Packaging | Raw-material category, not a fourth stock domain. |
| Part source | `MAKE`, `BUY`, or `BOTH`; one part record. |
| Sale eligibility | Finished products only; no direct raw-material or part sale. |
| Purchase | Raw materials and parts only; direct receipt allowed, PO optional. |
| Production | Separate part production and finished-product assembly flows. |
| Monthly rates | Company-wide; locked with the reporting period. |
| Stock valuation | Current-month approved rate. |
| Historical report | Uses and retains the selected month's locked rate snapshot. |
| Finance | Automatic balanced draft; accountant posts/voids. |
| QC | Basic good output, reject/wastage and reason. |
| Selling prices | Retail and wholesale on finished product. |

## 1. Conventions

- Table names are plural `snake_case`; Laravel model names are singular.
- PK: `BIGINT UNSIGNED AUTO_INCREMENT`.
- FK types match parent PK exactly.
- Quantity: `DECIMAL(20,6)`; money/rate: `DECIMAL(20,4)`.
- Posted operational documents use `DRAFT`, `POSTED`, `VOID` (plus workflow-specific states where needed).
- Posted ledger rows are immutable. Correction uses reversal/return/adjustment.
- Transaction tables store `created_by`, `updated_by` where appropriate and timestamps.
- Master tables use `is_active`; soft delete is allowed only where historical FKs remain safe.
- Code/barcode indexes must stay within the current MySQL/MariaDB key limit; indexed strings use explicit safe lengths (normally 80–191).

## 2. Retained existing foundation

Retain and clean rather than recreate:

- access: `users`, `roles`, `permissions`, route/role/user permission pivots;
- organisation: `branches`, user/branch access, `warehouses`, `company_settings`;
- location: `countries`, `divisions`, `districts`, `upazilas`;
- shared masters: `units`, `brands`, `suppliers`, `customers`, `payment_types`;
- finance masters: `account_types`, `accounts`, `branch_accounts`, `fiscal_years`, `voucher_types`;
- security: firewall tables/configuration.

Business Type is no longer an active application dependency. Existing migration/table history remains until the final migration cleanup policy is approved.

## 3. Master tables

### 3.1 Categories

Create `raw_material_categories`, `part_categories`, and `finished_product_categories` with:

| Column | Type / rule |
| --- | --- |
| `id` | PK |
| `parent_id` | nullable self FK; restrict delete |
| `code` | `VARCHAR(50)`, unique |
| `name` | `VARCHAR(150)` |
| `description` | nullable `TEXT` |
| `is_active` | boolean default true |
| audit | timestamps; optional soft delete |

### 3.2 `raw_materials`

| Column | Type / rule |
| --- | --- |
| `id` | PK |
| `material_code` | `VARCHAR(80)`, unique |
| `barcode` | nullable `VARCHAR(100)`, unique |
| `name` | `VARCHAR(200)` |
| `raw_material_category_id` | FK, restrict delete |
| `base_unit_id` | FK → `units`, restrict delete |
| `brand_id` | nullable FK → `brands` |
| `material_kind` | enum/string: `MATERIAL`, `PACKAGING`, `CONSUMABLE` |
| `specification`, `description`, `image_path` | nullable |
| `is_active` | boolean default true |
| audit | timestamps, soft delete |

### 3.3 `parts`

| Column | Type / rule |
| --- | --- |
| `id` | PK |
| `part_code` | `VARCHAR(80)`, unique |
| `barcode` | nullable `VARCHAR(100)`, unique |
| `name` | `VARCHAR(200)` |
| `part_category_id` | FK, restrict delete |
| `base_unit_id` | FK → `units` |
| `brand_id` | nullable FK → `brands` |
| `procurement_mode` | enum/string `MAKE`, `BUY`, `BOTH` |
| `specification`, `description`, `image_path` | nullable |
| `is_active` | boolean default true |
| audit | timestamps, soft delete |

### 3.4 `finished_products`

| Column | Type / rule |
| --- | --- |
| `id` | PK |
| `product_code` | `VARCHAR(80)`, unique |
| `barcode` | nullable `VARCHAR(100)`, unique |
| `name` | `VARCHAR(200)` |
| `finished_product_category_id` | FK, restrict delete |
| `base_unit_id` | FK → `units` |
| `brand_id` | nullable FK → `brands` |
| `retail_price`, `wholesale_price` | nullable amount; non-negative |
| `specification`, `description`, `image_path` | nullable |
| `is_active` | boolean default true |
| audit | timestamps, soft delete |

### 3.5 Unit and supplier mappings

- `raw_material_units(raw_material_id, unit_id, conversion_to_base, usage_type)`
- `part_units(part_id, unit_id, conversion_to_base, usage_type)`
- `finished_product_units(finished_product_id, unit_id, conversion_to_base, usage_type)`
- `raw_material_suppliers(raw_material_id, supplier_id, supplier_code, lead_days, is_preferred)`
- `part_suppliers(part_id, supplier_id, supplier_code, lead_days, is_preferred)`

Unique keys prevent duplicate domain-item/unit and domain-item/supplier mappings.

## 4. Purchasing

Use the approved explicit names `purchase_order`, `purchase_order_item`, `purchase_receipt`, `purchase_receipt_item`, `purchase_return`, `purchase_return_item`, and `purchase_payment`.

Item-line rule:

- `raw_material_id` nullable FK;
- `part_id` nullable FK;
- exactly one must be non-null (DB `CHECK` where enforced plus application validation);
- `finished_product_id` does not exist on purchase lines in release 1;
- store document unit, conversion, document quantity, base quantity, unit rate and line total.

Receipt posting writes either a raw-material movement or part movement. Actual supplier rate remains on the receipt item and is never overwritten by a monthly rate.

## 5. Separate stock cores

### 5.1 Movement ledgers

- `raw_material_stock_movements(raw_material_id, warehouse_id, ...)`
- `part_stock_movements(part_id, warehouse_id, ...)`
- `finished_product_stock_movements(finished_product_id, warehouse_id, ...)`

Common fields:

| Column | Type / rule |
| --- | --- |
| domain item FK | required |
| `branch_id`, `warehouse_id` | required FKs |
| `movement_at` | datetime, indexed |
| `movement_type` | controlled string/enum |
| `direction` | `IN` or `OUT` |
| `quantity` | positive base quantity |
| `unit_cost` | nullable actual/posted cost |
| `source_type`, `source_id`, `source_item_id` | traceable document reference |
| `reversal_of_id` | nullable self FK |
| `note`, `posted_by`, timestamps | audit |

Unique idempotency key: `(source_type, source_item_id, movement_type, direction)` where the DB engine permits the chosen pattern; otherwise enforce with a dedicated `posting_key`.

### 5.2 Balance summaries

- `raw_material_stock_balances(raw_material_id, warehouse_id, quantity, version)`
- `part_stock_balances(part_id, warehouse_id, quantity, version)`
- `finished_product_stock_balances(finished_product_id, warehouse_id, quantity, version)`

Unique key is `(domain_item_id, warehouse_id)`. Balances are updated in the same DB transaction as ledger insertion and can be reconciled from ledgers.

### 5.3 Reorder levels

Create separate `raw_material_reorder_levels`, `part_reorder_levels`, and `finished_product_reorder_levels`, unique by item and warehouse.

### 5.4 Transfer and adjustment documents

Shared headers:

- `stock_transfers`: branch/source warehouse/destination warehouse/date/status/posting audit;
- `stock_adjustments`: branch/warehouse/date/reason/type/status/posting audit.

Typed lines:

- raw/part/finished `*_transfer_items`;
- raw/part/finished `*_adjustment_items`.

Opening stock uses adjustment type `OPENING`. Transfer posts OUT and IN atomically.

## 6. BOM and production

### 6.1 Part production

- `part_boms`: output part, version, output quantity, status/effective dates;
- `part_bom_items`: raw material, unit, required/base quantity, wastage percentage;
- `part_production_batches`: BOM, branch, warehouses, planned/good/reject quantities, status;
- `part_production_consumptions`: raw material and actual consumed quantity/cost;
- `part_production_expenses`: category/account reference, amount and allocation.

Only `MAKE` or `BOTH` parts may have an active BOM or production batch.

### 6.2 Finished-product assembly

- `product_boms`: output finished product, version, output quantity, status/effective dates;
- `product_bom_items`: nullable `part_id`/`raw_material_id`, exactly one, unit and quantity;
- `product_assembly_batches`: product BOM, branch, warehouses, planned/good/reject quantities, status;
- `product_assembly_consumptions`: typed part/raw input and actual consumption/cost;
- `product_assembly_expenses`: category/account reference, amount and allocation.

Completion posts all consumption OUT and good output IN atomically.

## 7. Sales

Rebuild/adapt `sales`, `sale_items`, `sale_payments`, `sale_returns`, `sale_return_items`, and `sale_return_payments`.

`sale_items` has a required `finished_product_id` FK and no raw-material/part FK. It stores quantity, selling unit, unit price, discount/tax and total. Channel on the header is `POS` or `WHOLESALE`.

## 8. Monthly rates and reporting

- `reporting_periods`
- `monthly_rate_sheets`
- `monthly_raw_material_rates`
- `monthly_part_rates`
- `monthly_finished_product_rates`
- `monthly_report_snapshots`

Rate tables are unique by `(monthly_rate_sheet_id, domain_item_id)`. A closed period blocks edits and backdated posting unless reopened with permission/audit.

## 9. Demand and supply planning

- `demand_plans` and `demand_plan_items` target finished products.
- `material_requirement_plans` is the generated header.
- `raw_material_requirement_items` stores gross need, stock, incoming, safety and suggested purchase.
- `part_requirement_items` adds make quantity, buy quantity and suggested procurement based on `procurement_mode`.

The first release generates recommendations only; it does not auto-create purchase orders.

## 10. Finance integration

Retain finance master tables, but transaction posting must be rebuilt around:

- `journal_entries.status`: `DRAFT`, `POSTED`, `VOID`;
- durable `source_type` and `source_id`;
- balanced journal lines before posting;
- reversal instead of deletion for posted journals;
- configurable mapping for inventory, payable, cash/bank, production/WIP, sales, COGS, expenses and wastage.

## 11. Future inactive tables

Offer, coupon and loyalty foundations may remain migration-only/inactive. Every future eligible-item FK must target `finished_products`, never raw materials or parts. No first-release menu or posting logic uses these tables.

## 12. Migration dependency order

1. Retained foundation cleanup/alter migrations.
2. Three category/master groups and typed unit/supplier mappings.
3. Three stock ledgers, balances and reorder levels.
4. Purchase header/item/payment/return tables using the approved `purchase_*` names.
5. Transfer/adjustment headers and typed line tables.
6. Part BOM and part-production tables.
7. Product BOM and assembly tables.
8. Sales/payment/return tables.
9. Reporting periods and three monthly-rate tables.
10. Demand and requirement-planning tables.
11. Finance posting columns/rules.
12. Future inactive promotion/loyalty tables, if still approved.

## 13. Approval checklist

- [x] Separate raw-material, part and finished-product masters.
- [x] Separate stock movements and balances.
- [x] No raw-material or part direct sale.
- [x] Part `MAKE`/`BUY`/`BOTH`.
- [x] Company-wide monthly rate sheet.
- [x] Direct receipt allowed.
- [x] Automatic draft journal policy.
- [x] Basic QC.
- [x] Packaging/consumables are raw-material categories.
- [x] Mixed raw/part lines use typed nullable FKs; exactly one is populated, enforced in the application and by DB `CHECK` where supported.
- [x] Exact finance mapping is deliberately deferred; no draft journal may be posted until mapping is approved.
- [x] Opening masters use controlled CSV import; opening stock posts through approved `OPENING` adjustments.

The requirement and migration gates are complete. Future schema changes must be forward migrations reviewed against this baseline; the immediate next gate is foundation-data reconciliation and retained-module testing.
