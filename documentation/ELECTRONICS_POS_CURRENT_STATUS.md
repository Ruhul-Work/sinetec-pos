# Electronics POS — Current Status and Next Actions

**Status:** Phase 1 inventory foundation complete; Phase 2 stock core is next
**Updated:** 18 October 2026

## 1. Current result

- The new active database is `pos-sinetec`.
- The former database is retained as `pos-sinetec-legacy` for backup/reference.
- All nine clean migrations ran successfully in batch 1.
- The new database contains 113 tables: 112 application/framework tables plus Laravel's `migrations` table.
- The 116 generated legacy migrations remain archived outside Laravel's active migration path.
- Raw Material, Part and Finished Product use separate masters, stock movements and stock balances.
- Only Finished Product can be sold.
- Parts support `MAKE`, `BUY` and `BOTH`.
- Packaging and consumables are Raw Material classifications.
- Offers, coupons and loyalty are inactive future foundations only.

## 2. Database readiness

The schema is ready for module development. It is not yet operationally ready until foundation data is loaded and verified.

### Copy or recreate from `pos-sinetec-legacy`

Import only reviewed records from:

1. `roles` — retain only roles still required; ensure exactly one controlled Super Admin role.
2. `users` — password hashes may be copied; remap every `role_id` and `branch_id` to the new IDs.
3. `branches`.
4. `user_branches` — rebuild after users and branches exist.
5. `warehouses` — copy only active/required locations and verify branch mapping/default warehouse.
6. `company_settings` — copy business identity fields only.
7. `countries`, `divisions`, `districts`, `upazilas` if the retained location screens need their former data.
8. Reviewed shared masters such as `units`, `brands`, `payment_types`, `account_types`, `accounts`, `branch_accounts`, `fiscal_years` and `voucher_types`.

Suppliers and customers may be imported after their rows are checked against the new nullable/unique field rules.

### Do not copy from the legacy database

- `permissions`
- `permission_routes`
- `role_permissions`
- `user_permissions`
- legacy product/category/variant tables
- legacy purchase, sale, stock and ledger transactions
- old offer/coupon/loyalty data
- cache, session, job and log rows

Old permission data points to removed routes. Permissions must be created from the current application routes and extended as each new module is added.

## 3. Safe foundation-data order

1. Run the application seeders for voucher type, fiscal year and the clean core permission catalogue.
2. Create/import roles; mark only the intended administrator role as `is_super = 1`.
3. Import branches and warehouses.
4. Import users with explicit old-to-new role and branch ID mapping.
5. Rebuild `user_branches` and role-permission matrices.
6. Import company/location/shared master data.
7. Test login, branch switching and every retained menu.
8. Import raw materials, parts and finished products only through the new master formats.
9. Enter opening inventory through approved `OPENING` stock adjustments; never edit stock balances directly.

## 4. Known application work before full use

- Dashboard legacy business queries are removed; cards/charts now use a safe zero state until each new module supplies its approved metrics.
- Phase 0.5 application cleanup is complete: route-detached legacy product, purchase, POS, stock, report, promotion and old transaction controllers/models/services/views were removed after the new Git baseline tag was created.
- The retained Supplier controller is now master-data only; its old purchase/return/ledger coupling was removed and its browser-parsed CSV/XLSX import was aligned with the clean supplier schema.
- The shared helper file now contains only retained image upload/path and branch/warehouse/fiscal-year context helpers; legacy storefront product, cart, coupon and shipping helpers were removed.
- The active application currently exposes 289 retained/new routes, with no undefined literal route references in backend/auth Blade templates.
- The first Phase 1 module is complete: Units and company-wide universal Unit Conversions have CRUD screens, reverse conversion display, duplicate-pair protection, reference-safe unit deletion and automated feature coverage.
- The three Phase 1 category masters are complete: Raw Material, Part and Finished Product categories have separate CRUD routes/tables/permissions, optional parent hierarchy, cycle protection, Select2 parent lookup and reference-safe deletion.
- Raw Material Master is implemented with material/packaging/consumable classification, category/base-unit/optional-brand lookup, image handling, list filters, independent RBAC and downstream-reference delete protection. Stock quantity, rates, alternate units and suppliers are intentionally not stored in this master.
- Part Master is implemented with `MAKE`/`BUY`/`BOTH` procurement modes, category/base-unit/optional-brand lookup, image handling, list filters, independent RBAC and downstream-reference delete protection. Made and purchased quantities will later enter the same part stock ledger.
- Finished Product Master is implemented with category/base-unit/optional-brand lookup, optional default retail/wholesale prices, image handling, filters, independent RBAC and downstream-reference delete protection. The defaults do not replace immutable sale-line and approved monthly-report price snapshots.
- Domain Unit Mapping is implemented through one compact tabbed screen backed by separate `raw_material_units`, `part_units` and `finished_product_units` tables. Base units remain implicit at factor 1; duplicate/base-unit mappings and process-incompatible usage are blocked.
- Raw Material Supplier and Part Supplier Mapping are implemented as separate route/controller/RBAC modules backed by `raw_material_suppliers` and `part_suppliers`. Each mapping stores the supplier's item code, lead days and one preferred source per item; purchase prices remain transaction-derived.
- Warehouse-wise Raw Material, Part and Finished Product Reorder Levels are implemented as separate route/controller/RBAC modules. Minimum quantity is required; fixed replenishment and maximum/target quantities are optional. A blank fixed quantity is stored as zero and means future demand-based planning rather than an automatic purchase or production action.
- A reusable Sinetec Image Picker now progressively enhances a standard `<input type="file" class="js-image-picker">` with compact dashed upload, preview, filename/size, replace/remove, drag-and-drop and configurable single/multiple modes on both full pages and AJAX modals. Raw Material, Part, Finished Product, Payment Type, Brand, Supplier and Customer now use it; no active retained screen executes Spartan.
- Build models/controllers/services/views for the new inventory and manufacturing tables.
- Rebuild stock posting as transaction-safe services.
- Rebuild automatic finance drafts; a journal cannot be posted before account mapping and approval.
- Test retained Supplier/Customer CSV imports against the clean schema.
- Review public registration before production; unrestricted role selection must not be exposed.

### Immediate development start

1. Complete the retained foundation-data smoke test on the clean database.
2. **Complete:** Build Units & Unit Conversion as the first Phase 1 module.
3. **Complete:** Build the three independent category masters.
4. **Complete:** Build Raw Material Master without mixing stock, price, alternate-unit or supplier state into the master record.
5. **Complete:** Build Part Master with `MAKE`, `BUY` and `BOTH` procurement modes.
6. **Complete:** Build Finished Product Master with optional default retail/wholesale prices.
7. **Complete:** Build the three domain unit-mapping flows without combining their physical tables.
8. **Complete:** Build raw-material/part supplier mappings with separate permissions.
9. **Complete:** Build warehouse-wise Raw Material, Part and Finished Product reorder-level setup.
10. **Next:** Build the three transaction-safe stock posting services, movement ledgers and current-balance flow.

## 5. Implementation decision trace

| Date | Area | Implemented decision | Deferred / dependency |
| --- | --- | --- | --- |
| 8 Oct 2026 | Units | One company-wide unit master and universal conversion pairs; reverse duplicate pairs are blocked. | Per-item usable units are configured later in domain unit mappings. |
| 8 Oct 2026 | Categories | Raw Material, Part and Finished Product categories remain in three independent tables/modules; optional same-domain parent hierarchy is supported. | Category records do not carry stock, price or accounting state. |
| 8 Oct 2026 | Raw Material Master | `raw_materials` stores identity and classification only: code, barcode, name, category, base unit, optional brand, kind, specification, description, image and status. | Stock ledger/balance, monthly rates, alternate units, supplier mapping and reorder levels remain separate roadmap modules. |
| 8 Oct 2026 | Raw-material deletion | An unused record may be soft-deleted; any purchase, stock, BOM, production, rate, planning, unit or supplier reference blocks deletion and requires deactivation instead. | No historical transaction may lose its master reference. |
| 8 Oct 2026 | Development data | Do not create or seed sample/demo business data unless the user explicitly requests it. | Required operational data will be entered/imported through approved setup flows. |
| 10 Oct 2026 | Part Master | `parts` stores identity, classification and sourcing policy only; `MAKE`, `BUY` and `BOTH` all represent one part identity and feed one future stock ledger. | Stock quantity, transaction cost, monthly rate, alternate units, supplier mapping and reorder levels remain separate modules. |
| 10 Oct 2026 | Part deletion | An unused part may be soft-deleted; purchase, stock, BOM, assembly, rate, planning, unit or supplier references block deletion and require deactivation. | Production and inventory history must retain its part reference. |
| 10 Oct 2026 | Image picker | New work uses one dependency-free global picker by adding `js-image-picker` to a normal file input; native `multiple` and `data-*` options control mode, limits and existing images. Raw Material, Part, Finished Product, Payment Type, Brand, Supplier and Customer use it on full pages and AJAX modals. | Spartan is no longer executed by any retained screen; its static plugin file remains only as recoverable reference for now. |
| 10 Oct 2026 | Finished Product Master | `finished_products` stores sellable-product identity, category, base unit, optional brand, optional default retail/wholesale prices, specification, description, image and status. Only this domain will be selectable by POS/wholesale sales. | Stock, calculated manufacturing cost, alternate units, monthly approved rates and sale-line price snapshots remain separate modules. |
| 10 Oct 2026 | Finished-product deletion | An unused finished product may be soft-deleted; inventory, BOM, sales, rates, planning, units or promotion references block deletion and require deactivation. | Assembly and sales history must retain its product reference. |
| 10 Oct 2026 | Domain Unit Mapping | One `Item Units` UI uses Raw Material, Part and Finished Product tabs while persisting to three independent mapping tables. Base units are implicit at factor 1; alternative conversion is stored as `1 alternative unit = N base units`. Raw Material/Part exclude sale usage and Finished Product excludes purchase usage. | Supplier mappings and warehouse reorder levels remain the next Phase 1 setup modules. |
| 10 Oct 2026 | Page notes | General instructional notes use the reusable compact `page-note` header control and open on demand instead of occupying permanent space above the page content. | Conditional warnings and validation/security alerts remain visibly inline. |
| 11 Oct 2026 | Supplier mappings | Raw Material Supplier and Part Supplier are separate route/controller/permission modules. They store approved item-supplier links, the supplier's item code, lead days and a single preferred source per item. | Actual prices remain in purchase/history data; warehouse reorder levels are next. |
| 11 Oct 2026 | Route/RBAC convention | New functionally separate modules use explicit route groups and independent permission keys, even when safe internal implementation code or views are shared. | Existing Item Units remains one approved shared permission unless domain-specific access is later requested. |
| 18 Oct 2026 | Reorder levels | Raw Material, Part and Finished Product use separate warehouse-wise modules/tables/permissions. Minimum quantity is required; fixed replenishment quantity and maximum/target quantity are optional. A blank replenishment value is stored as zero and means demand-based planning. | The setup only provides thresholds and planning inputs; it never creates purchase, production or stock transactions automatically. |

When a decision changes, add a new dated row; do not overwrite the old decision without recording what superseded it and why.

## 6. Authoritative documents

Only these documents are active:

1. [Project Plan](ELECTRONICS_POS_PROJECT_PLAN.md) — approved business scope and decisions.
2. [Physical Database Design](ELECTRONICS_POS_PHYSICAL_DB_DESIGN.md) — implemented schema rules and migration groups.
3. [Development Roadmap](ELECTRONICS_POS_DEVELOPMENT_ROADMAP.md) — module sequence and acceptance gates.
4. This file — current status, data setup and immediate next actions.

Older conceptual, meeting-dictionary, reuse-audit and standalone migration-manifest drafts were removed after their accepted decisions were consolidated into these four documents.
