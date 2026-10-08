# Electronics POS — Current Status and Next Actions

**Status:** Database baseline ready; foundation-data setup in progress  
**Updated:** 8 October 2026

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
- The active application currently exposes 179 retained routes, with no undefined literal route references in backend/auth Blade templates.
- Build models/controllers/services/views for the new inventory and manufacturing tables.
- Rebuild stock posting as transaction-safe services.
- Rebuild automatic finance drafts; a journal cannot be posted before account mapping and approval.
- Test retained Supplier/Customer CSV imports against the clean schema.
- Review public registration before production; unrestricted role selection must not be exposed.

### Immediate development start

1. Complete the retained foundation-data smoke test on the clean database.
2. Build Units & Unit Conversion as the first Phase 1 module.
3. Continue with the three category masters and then Raw Material, Part and Finished Product masters.

## 5. Authoritative documents

Only these documents are active:

1. [Project Plan](ELECTRONICS_POS_PROJECT_PLAN.md) — approved business scope and decisions.
2. [Physical Database Design](ELECTRONICS_POS_PHYSICAL_DB_DESIGN.md) — implemented schema rules and migration groups.
3. [Development Roadmap](ELECTRONICS_POS_DEVELOPMENT_ROADMAP.md) — module sequence and acceptance gates.
4. This file — current status, data setup and immediate next actions.

Older conceptual, meeting-dictionary, reuse-audit and standalone migration-manifest drafts were removed after their accepted decisions were consolidated into these four documents.
