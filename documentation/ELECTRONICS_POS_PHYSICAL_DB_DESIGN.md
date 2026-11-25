# Electronics POS — Physical Database Design (v2.9)

**Status:** Implemented clean-schema baseline with reporting, snapshot, Demand Planning and hardened MRP extensions
**Version:** 2.9
**Updated:** 25 November 2026
**Source:** Project Plan v2.13 and the active migrations in `core/database/migrations`

The clean baseline was successfully migrated to `pos-sinetec`: 112 application/framework tables plus Laravel's `migrations` table, for 113 total. A forward migration defines the Reporting Period audit-event table. The former database remains `pos-sinetec-legacy`. This document describes the design boundary; the active migrations are authoritative for exact executable columns and constraints.

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

Finished Product labels use internal EAN-13. When a selected product has no barcode, preview generation assigns one valid, unique 13-digit number once and persists it in the existing `barcode` column. Later prints reuse that number. No configurable/company prefix is required in the current internal-POS scope, rendered barcode images are not stored, and the A4 label contains only Product Name, barcode symbol and its human-readable number. The implementation remains reusable for Raw Material/Part labels or an official GS1 prefix if later approved.

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

Purchase-order and purchase-receipt discount snapshot:

- `discount_type` is `FLAT` or `PERCENT`;
- `discount_value` stores the user's entered flat amount or percentage;
- `discount` remains the calculated monetary amount used by totals and historical reports;
- percentage value is limited to 100 and calculated against the server-derived current subtotal while the document is a Draft;
- existing orders and receipts are migrated as `FLAT`, with their previous `discount` copied to `discount_value`.

Receipt posting writes either a raw-material movement or part movement. Actual supplier rate remains on the receipt item and is never overwritten by a monthly rate.

Receipt workflow rules:

- `purchase_order_id` is nullable because direct receipt is allowed;
- PO-based receipt lines reference `purchase_order_item_id` and may receive a pending quantity partially;
- posting locks receipt, order and order lines; cumulative receipt cannot exceed ordered base quantity;
- posting writes deterministic stock keys so a retry cannot duplicate stock;
- PO status becomes `PARTIAL` while any ordered base quantity remains and `RECEIVED` when every line is complete;
- optional supplier invoice attachment belongs to the receipt, not the order; `supplier_invoice_path` stores its managed relative path. The legacy nullable `supplier_invoice_no` remains compatible with earlier data.

Purchase-return workflow rules:

- every return references one posted `purchase_receipt`; direct/unlinked supplier returns are not allowed in release 1;
- supplier, branch and warehouse are inherited from the source receipt and cannot be changed;
- each `purchase_return_item` references its source `purchase_receipt_item`; item, unit, conversion and original receipt rate are copied server-side;
- cumulative posted return base quantity cannot exceed the source receipt-item base quantity;
- Draft return has no stock effect; posting writes an idempotent Raw Material/Part `OUT` movement in the receipt warehouse;
- posting locks and rechecks the document and source quantities, blocks negative stock and rolls back every line if any line fails;
- posting also creates the balanced Purchase Return Draft Journal using the effective purchase account mappings. Accountant approval and posting govern the general-ledger effect.

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

Create separate `raw_material_reorder_levels`, `part_reorder_levels`, and `finished_product_reorder_levels`, unique by item and warehouse. `minimum_quantity` is the required low-stock threshold. `reorder_quantity = 0` means there is no fixed replenishment lot and future demand-based planning decides the requirement; a positive value is an optional fixed/default lot. `maximum_quantity` is an optional target ceiling and must not be lower than the minimum. All values use the item's base unit. These settings never post stock or create purchase/production documents automatically.

The operational Low-Stock Alert report unions these three domain tables with their matching warehouse balances. A row is actionable when current stock is equal to or below `minimum_quantity`. It classifies Out of Stock, Low Stock and At Reorder Point, then recommends Purchase, Part Production, Make/Buy review or Finished Product Assembly. Suggested quantities respect the configured default replenishment lot and optional maximum target; a zero lot without a maximum remains demand-plan driven. The report is read-only and branch-scoped.

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
- `part_production_expenses`: production cost category, amount and batch allocation for operational estimate/reporting only; no ledger account or payment is required.
- `expenses.part_production_batch_id` and `expense_items` remain legacy-compatible schema only. The active workflow does not create production settlements.

Only `MAKE` or `BOTH` parts may have an active BOM or production batch.

### 6.2 Finished-product assembly

- `product_boms`: output finished product, version, output quantity, status/effective dates;
- `product_bom_items`: nullable `part_id`/`raw_material_id`, exactly one, unit and quantity;
- `product_assembly_batches`: product BOM, branch, warehouses, planned/good/reject quantities, status;
- `product_assembly_consumptions`: typed part/raw input and actual consumption/cost;
- `product_assembly_expenses`: category/account reference, amount and allocation.

Completion posts all consumption OUT and good output IN atomically. Assembly cost values will follow the same report-only rule as Part Production and will not create a Finance journal.

## 7. Sales

Rebuild/adapt `sales`, `sale_items`, `sale_payments`, `sale_returns`, `sale_return_items`, and `sale_return_payments`.

`sale_items` has a required `finished_product_id` FK and no raw-material/part FK. It stores quantity, selling unit, unit price, discount/tax and total. Channel on the header is `POS` or `WHOLESALE`.

POS and Wholesale are separate operator interfaces over these shared tables and services. `sales.channel` records which interface/business flow created the document; it does not create separate POS and wholesale transaction tables. An All Sales view therefore remains the common audit list.

The sale Draft service owns all monetary calculations. Client-side totals are previews only; the server revalidates the selling unit/conversion, quantity, price, flat/percentage document discount, tax and final total. POS and Wholesale use immutable line-rate snapshots so later Finished Product price changes cannot alter an existing sale.

The POS wide-screen layout reuses the Purchase gallery/cart pattern. Wholesale uses a compact searchable line-entry table so customer terms and negotiated rates remain visible. Layout choice does not change the shared persistence or posting rules.

Implemented Sales Draft foundation:

- `sales.discount_type` is `FLAT` or `PERCENT`; `discount_value` preserves the operator input while `discount` stores the calculated amount;
- `sales.customer_reference` stores the optional customer PO/reference and `sales.due_date` stores the mandatory Wholesale payment term date. Both remain nullable at database level because POS does not use them; the Wholesale service/controller enforces its business rules.
- channel and status are typed application enums;
- Wholesale requires an active customer. POS keeps `customer_id` nullable and displays a null customer as the virtual `Walk-in Customer`, avoiding a shared customer-ledger row;
- only active Finished Products and their base unit or a mapping enabled for `SALE`/`ALL` are accepted;
- the service calculates base quantity, immutable line price, line total, header discount, tax, total and initial due on the server;
- Draft create/update/delete has no stock or Finance effect;
- The POS Draft UI is implemented as a permission-isolated, approximately 55/45 gallery-and-cart workspace. Its catalogue reads warehouse-specific Finished Product balances, disables zero-stock cards and keeps the responsive Purchase workspace interaction pattern.
- POS payment review accepts one or more `sale_payments` rows, so Cash, Bank and MFS split payment is preserved at account level. `amount` is the applied/accounting amount; `tendered_amount` preserves what the customer handed over and `change_amount` preserves returned Cash. Only Payment Types explicitly marked `allows_change` may exceed the remaining total. Walk-in checkout requires full applied payment; a registered customer may retain a receivable balance.
- The controlled lifecycle is `DRAFT → READY_TO_POST → POSTED`. Payment review creates Draft `sale_payments` and marks the Sale `READY_TO_POST`; it does not change stock or Finance. Back to Edit removes only those prepared Draft payments and restores the Sale Draft. Final confirmation locks and revalidates the prepared document, then atomically posts Finished Product stock OUT, payment rows, the Sale totals/status and a balanced Sales journal. The operational POS journal is automatically approved and posted; any failure rolls back the complete confirmation.
- The 80mm thermal receipt is available only for a successfully `POSTED` Sale.
- Wholesale Sales reuses the same lifecycle and posting engine. It requires a registered customer/due date, defaults line rates from `wholesale_price` while allowing negotiated snapshots, accepts split/partial payment or full due, and exposes a printable A4 invoice after posting.
- `customer_receipts` stores one immutable posted due-collection header with Sale, Customer, Branch, collection time, amount and due-before/due-after snapshots. Its split Cash/Bank/MFS rows reuse `sale_payments.customer_receipt_id`, so the Sale payment breakdown remains complete.
- Customer due collection never reposts Sale stock or revenue. It atomically increases `sales.paid_amount`, reduces `sales.due_amount`, and posts a Receipt journal: Debit the selected receiving account(s), Credit Customer Receivable. Outstanding Dues and Collection History are branch-scoped views over these source records.
- Sales Finance uses company/branch-resolved mappings for Customer Receivable, Output Tax and Sales Revenue. Cash/Bank/MFS debits come from the branch-assigned receiving accounts selected on each payment row.

## 8. Monthly rates and reporting

- `reporting_periods`
- `reporting_period_events`
- `monthly_rate_sheets`
- `monthly_raw_material_rates`
- `monthly_part_rates`
- `monthly_finished_product_rates`
- `monthly_report_snapshots`

`reporting_period_events` retains each Open, Close and Reopen action with its actor, reason and timestamp. Reporting periods are company-wide calendar months; a month can be closed only after its end date, and reopening requires separate permission and an audit reason.

Rate tables are unique by `(monthly_rate_sheet_id, domain_item_id)`. Monthly Rate Sheet edits are restricted to an Open period. Final snapshot generation requires the Rate Sheet to be Locked and the Reporting Period to be Closed; an audited reopen/reclose is required before a corrected snapshot version can be generated.

`monthly_report_snapshots` stores one `MONTHLY_PACKAGE` version per closed period and scope. `scope_type` is `COMPANY` or `BRANCH`; `branch_id` is required only for branch scope and `scope_key` provides explicit uniqueness. Its JSON payload freezes all six report sections and the permitted branch/warehouse dimensions, so views never recalculate from live transaction tables. `version`, `status`, `supersedes_snapshot_id`, correction note and superseded actor/time retain a non-destructive scope-specific revision chain. `checksum` stores the SHA-256 payload digest. The unique key is `(reporting_period_id, report_type, scope_key, version)`; correction requires an audited reopen/reclose.

### 8.1 Operational dashboard read model

The dashboard reads posted `sales`, `sale_returns`, `purchase_receipt` and general `expenses` for its KPIs and charts. Net sales subtract posted returns by return date. Purchase figures use posted receipt totals and the operating expense series uses posted general expense amounts. The recent document list additionally reads `purchase_return` and checks the mapped document View permission. All queries are scoped to the selected or assigned branch, except when a Super Admin selects All Branches. The dashboard uses existing tables and does not require a migration.

## 9. Demand and supply planning

- `demand_plans` and `demand_plan_items` target finished products.
- `material_requirement_plans` is the generated branch/period header and is unique by source `demand_plan_id`.
- `mrp_finished_product_lines` freezes Demand quantity, Safety quantity, available Finished Product stock and net assembly requirement.
- `mrp_component_lines` freezes each exploded Raw Material/Part gross requirement, minimum-stock safety buffer, pending incoming Purchase quantity, available branch stock, net requirement and separate Make/Buy quantities.
- `purchase_order.material_requirement_plan_id` provides source traceability from an MRP supplier recommendation to its Purchase Order.

Implemented Demand Plan rules:

- one canonical `demand_plans` row is allowed per `(branch_id, reporting_period_id)`;
- `branch_id` is required by the application and access follows the selected/assigned branch scope;
- `history_months` records the selected 1, 3, 6 or 12-month lookback;
- each `demand_plan_items` row snapshots `historical_net_quantity` and its monthly-average `suggested_quantity`, while `forecast_quantity` and `safety_quantity` preserve the approved business target;
- only Draft plans are editable; approval freezes the plan for Material Requirement Planning;
- Demand Plan create/approve/cancel has no stock, Purchase or Finance posting effect.

Implemented MRP rules:

- only an Approved Demand Plan can generate one MRP Draft;
- current branch Finished Product stock is deducted before Product BOM explosion;
- the Product BOM effective at the target period start is used; a missing or empty effective BOM blocks generation with a clear product-specific error;
- Product BOM base quantities and wastage are expanded into Raw Material/Part gross requirements;
- each component is calculated as `gross BOM requirement + minimum-stock safety buffer - current branch stock - incoming approved/partial Purchase quantity`;
- incoming Purchase quantity is counted only when its expected date is blank or falls on/before the target period end;
- Raw Materials are planned as Buy; Parts follow `MAKE`, `BUY` or `BOTH`, where `BOTH` prefers Buy when an active supplier/open Purchase source exists and otherwise falls back to Make;
- all planning quantities and recommendation rates are normalized to the component base unit;
- Draft approval freezes the plan; generation and approval do not post stock or Finance;
- Buy shortages are grouped by preferred active supplier, estimated from a base-unit-normalized Purchase/rate source, and can prefill a source-linked Purchase Order Draft.
- an MRP-sourced Purchase Order requires an Approved plan, matching branch warehouse, recommended supplier/items and no duplicate active order for the same supplier.

## 10. Finance integration

Retain finance master tables, but transaction posting must be rebuilt around:

- `journal_entries.status`: `DRAFT`, `POSTED`, `VOID`;
- durable `source_type` and `source_id`, unique together for one journal per source document;
- mandatory unique `posting_key` for retry-safe journal creation;
- balanced journal lines before posting;
- reversal instead of deletion for posted journals;
- configurable mapping for inventory, payable, cash/bank, production/WIP, sales, COGS, expenses and wastage.

Implemented finance-foundation rules:

- account types have mandatory codes and `DEBIT`/`CREDIT` normal balances; starter types/accounts are idempotent system records;
- `accounts.is_cash_bank_account` marks liquid Asset accounts eligible for Supplier Payment and Cash/Bank Book; starter `1000` Cash in Hand and `1010` Bank Account are marked automatically;
- account codes are mandatory and unique, hierarchy cycles are rejected, and mapped accounts cannot be disabled;
- fiscal-year date ranges cannot overlap and only one fiscal year is active;
- `finance_account_mappings` uses explicit `COMPANY` or `BRANCH` scope plus a unique `scope_key + mapping_key` pair, avoiding nullable-branch uniqueness ambiguity;
- purchase mapping keys are `RAW_MATERIAL_INVENTORY`, `PART_INVENTORY`, `SUPPLIER_PAYABLE`, `INPUT_TAX`, and `PURCHASE_DISCOUNT`;
- production has no Finance mapping: BOM, consumption, QC and batch/unit cost are operational records only; actual salary, electricity, rent, transport and similar payments use General Expense;
- effective mapping resolves branch override first and company default second; branch overrides require an active branch-account assignment;
- the generic Draft Journal service accepts only active branch-assigned BDT accounts, exactly balanced four-decimal lines and a journal date covered by the single active fiscal year;
- identical posting/source retries return the original journal, while conflicting reuse is rejected; the service creates `DRAFT` only and has no automatic posting authority;
- Purchase Receipt/Return posting creates balanced `DRAFT` journals atomically with stock effects. The retry-safe reconciliation command is `finance:reconcile-purchase-journals`.
- A Draft must be balanced and approved before posting. Posting revalidates active branch, covering active fiscal year and every active BDT branch-assigned account, then records `posted_by` and `posted_at`; only `POSTED` journals affect balances.
- A Draft may be voided with `voided_by`, `voided_at` and a required reason. A posted journal cannot be voided or changed; one linked reversal Draft (`reversal_of_id` is unique) swaps every debit/credit line and itself requires approval and posting.
- Account Ledger is a read-only derived view over `POSTED` `journal_entry_lines` plus `POSTED` `account_opening_balances`. It partitions chronological running balance by `account_id` and `branch_id`; date filters hide rows but do not reset the true balance brought forward.
- Cash/Bank Book is a narrower read-only derived view over those same Posted sources, filtered to `accounts.is_cash_bank_account = true`; no duplicate cash ledger table is stored.
- `finance_capital_investments` stores controlled Owner/Partner capital documents: branch, selected Cash/Bank Asset account, selected Equity account, date, amount, reference/note and Draft/Posted/Void audit fields. Its generated Receipt Voucher journal uses `source_type = capital_investment`; no duplicate ledger balance is stored.
- `finance_loan_receipts` stores controlled temporary Director/Partner funding: branch, selected Cash/Bank Asset account, selected Liability account, date, amount, reference/note and Draft/Posted/Void audit fields. Its generated Receipt Voucher journal uses `source_type = loan_receipt`; repayment will use a linked future source document.
- `finance_capital_withdrawals` records Owner/Partner Drawings and `finance_cash_bank_transfers` records same-branch Cash/Bank movements. Both generate Draft Journals; transfer always debits destination and credits source without changing total funds.
- `finance_loan_repayments` references one posted `finance_loan_receipt`, selected branch Cash/Bank account, repayment date, amount, reference/note and Draft/Posted/Void audit fields. A repayment amount cannot exceed the source loan's posted outstanding principal; its Draft Journal debits Loan Liability and credits Cash/Bank.
- Trial Balance is intentionally a derived report only: it unions Posted `account_opening_balances` and Posted `journal_entry_lines`, then groups by account for the scoped branch and as-of date. No `trial_balance` table is stored, so the report remains reconciled to the authoritative journal/opening rows.
- `finance_fiscal_year_closings` stores controlled year-end Drawings-to-Capital documents: fiscal year, branch, selected drawings/capital account pair, end-date-only closing date, server-calculated amount, reference/note and Draft/Prepared/Void audit fields. It creates a `journal_entries` source row with `source_type = fiscal_year_closing`; no duplicate ledger balance is stored. A prepared closing Journal must be Posted before the fiscal-year switch is permitted.

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
- [x] Finance account mapping is implemented with company defaults and optional branch overrides. Production cutover still requires business approval of the mappings used.
- [x] Opening inventory posts through the existing approved opening workflow. Use module-supported imports for masters where available and manual entry for opening stock. Bulk opening-stock CSV is deferred.

The implemented schema supports the current modules. For cutover, rehearse forward migrations on a database copy and reconcile master data, opening inventory and financial balances.
