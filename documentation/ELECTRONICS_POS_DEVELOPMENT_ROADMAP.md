# Electronics POS — Development Roadmap

**Status:** Active implementation roadmap; Purchasing, inventory core, operational Finance foundation, Part BOM and stock-only/report-only Internal Part Production are complete; Finished Product BOM is next
**Version:** 1.3
**Updated:** 10 November 2026
**Based on:** Project Plan v1.0 and Physical Database Design v2.1

> **Decision override:** Any later reference in this document to a unified `inventory_item`, `inventory_movement`, or `inventory_balance` means the corresponding separate Raw Material, Part, and Finished Product masters/ledgers/balances. The unified-table proposal is cancelled.

> **Current delivery position:** Phases 0-3 are complete. The purchase-related Finance foundation and accountant workflow from Phase 6 were completed early. Phase 4 is in progress: Part BOM, Production Cost Categories and stock-only Internal Part Production are complete. Production cost values are report-only and do not create journals or settlements. Finished Product BOM and Assembly are next.

## 1. Roadmap objective

এই document-এর উদ্দেশ্য হলো development শুরু করার সঠিক ক্রম নির্ধারণ করা—কোন module আগে হবে, কোন existing panel reuse হবে, কোন module কোন data/service-এর উপর নির্ভর করবে, এবং প্রতিটি phase শেষ হওয়ার পরে কী test করে পরের phase-এ যাওয়া যাবে।

প্রথম release-এর লক্ষ্য: সহজ operational flow-তে raw material/part purchase, stock, BOM, production, finished-good sale, draft finance journal এবং month-based reporting চালু করা।

## 2. Delivery principles

1. **Foundation আগে, screen পরে।** Item, warehouse এবং stock ledger ছাড়া purchase/production/sale screen final করা যাবে না।
2. **একটি stock-posting service।** Purchase, production, sale, return, transfer ও adjustment একই transaction-safe inventory movement service ব্যবহার করবে।
3. **Posted document immutable।** ভুল হলে edit নয়; return, void, reversal বা adjustment দিয়ে correction হবে।
4. **Legacy retail product core আলাদা থাকবে।** নতুন manufacturing core-এ separate `raw_materials`, `parts`, and `finished_products` ব্যবহার হবে; নতুন flow legacy `products`/product-bound stock ledger-এ যুক্ত হবে না।
5. **ছোট usable release।** প্রতিটি phase শেষে বাস্তব user দিয়ে test করা যাবে; সব module একসঙ্গে শেষ হওয়ার অপেক্ষা নয়।
6. **Finance draft, not blind posting।** Business event থেকে draft journal তৈরি হবে; finance team review করে post/void করবে।
7. **Readable code is a delivery gate.** নতুন বা refactored controller, service, model, route এবং Blade code compact/minified structural style-এ লেখা যাবে না. দায়িত্বভিত্তিক ছোট method, meaningful name, explicit validation/guard, predictable error path এবং testable transaction flow বাধ্যতামূলক।
8. **Safe change path.** Existing migrated schema পরিবর্তনে forward migration ব্যবহার হবে; posted stock/finance document edit/delete নয়, controlled reversal/correction হবে।

## 3. Existing panel reuse boundary

| Panel/module now | Roadmap treatment | When used |
| --- | --- | --- |
| Login, users, roles, permissions, branch switch | Retain | Phase 0–1 থেকেই |
| Branches, users branches, warehouses | Retain/adapt | Phase 1 |
| Units, suppliers, customers, brands, accounts | Retain/light adapt | Phase 1 |
| Legacy `products`, variants, old product stock | Do not extend as new core | Legacy only; replacement begins Phase 1 |
| Current purchase UI/pattern | Reuse UX ideas; rebuild business posting | Phase 3 |
| Current stock transfer/adjustment UI/pattern | Reuse UX ideas; rebuild against new movement core | Phase 2 |
| Current POS/sale UI/pattern | Adapt after new inventory core is ready | Phase 5 |
| Expense/accounts/journal foundation | Retain and verify | Phase 4, 6 |
| Existing reports/print layouts | Reuse presentation pattern; rebuild queries | Phase 7 |
| Existing offer/coupon/loyalty screens | Keep inactive; do not connect to first release | Future phase |

## 4. Module connection map

```text
Access + Branch + Master Data
             |
             v
Raw Material + Part + Finished Product Masters + Warehouse Setup
             |
             v
Three Domain Stock Services + Balance Summaries
       /          |             |           \
      v           v             v            v
Purchase     Transfer/       BOM &        Opening Stock /
Receipt      Adjustment      Production   Stock Count
      |                         |              |
      +-------------> Inventory Ledger <------+
                              |
                    +---------+---------+
                    v                   v
            POS / Wholesale Sale     Finance Draft Journal
                    |                   |
                    +--------+----------+
                             v
              Monthly Rate / Reports / Supply Planning
```

### Core data connection rules

| Source module | Creates/uses | Connected module | Important rule |
| --- | --- | --- | --- |
| Three inventory masters | raw materials, parts, finished products, shared units/brands | Purchase, BOM, production, sale, rate sheet | Raw/part direct sale is impossible because sale items reference finished products only. |
| Warehouse setup | warehouse type and branch | All stock-changing modules | Every movement needs source/destination warehouse context. |
| Purchase receipt | receipt lines, actual supplier rate | Inventory + finance | Posts `PURCHASE_IN`; PO is optional. |
| BOM | finished item and components | Production + supply plan | Only component items can be BOM input. |
| Production batch | actual consumption, output, QC, expense | Inventory + finance + reports | Atomic component OUT and output IN. |
| Sale | finished product line, payment | Inventory + finance + report | Only sellable `FINISHED_GOOD` can be sold. |
| Monthly rate sheet | period/item reporting rate | Stock valuation, production/sales report, planning | One company-wide rate sheet; closed month locks history. |
| Demand plan | finished-product target | BOM + stock + purchase suggestion | Generates recommendation only, no automatic PO. |

## 5. Phase-by-phase development plan

### Phase 0 — Final preparation and technical baseline — Complete

**Start here.** No manufacturing feature should be coded before this phase is complete.

| Work item | Existing panel connection | Output |
| --- | --- | --- |
| **Complete:** Approve final table dictionary and naming | Documentation / DB | Signed-off migration scope |
| **Complete:** Confirm legacy-data policy | Existing POS DB | List of retained masters and excluded old transactions |
| **Complete:** Review migration order and fresh-database install | Laravel migrations | Reproducible empty DB setup |
| **Complete:** Add module permission list | Existing RBAC | Permission keys for inventory, purchase, BOM, production, rate, planning, reports |
| **Complete (Purchase):** Decide finance account mapping | Existing accounts/vouchers | Purchase inventory/payable/tax/discount mapping; production/sales mapping remains a later event-specific design item |
| **Complete:** Prepare clean foundation-data import order | Existing branch/unit/supplier/customer data | Controlled master-data setup sequence |

**Exit criteria**

- Table names, fields and migration strategy are approved.
- A developer can run migrations on a blank local database without legacy-table conflict.
- Finance owner confirms draft-journal account mapping or explicitly defers a mapping.
- Roles/permissions for new routes are agreed.

### Phase 1 — Administration, master data and warehouse foundation — Complete

**Why first:** Every later document needs item, unit, branch and warehouse references.

| Build / adapt | Reuse or new | Depends on | Connects to |
| --- | --- | --- | --- |
| **Complete:** New module permissions and menu groups | Reuse RBAC | Phase 0 | All future modules |
| **Complete:** Branch/warehouse foundation | Adapt `branches`, `warehouses` | Phase 0 | Inventory, purchase, production, sales |
| **Complete:** Units and universal unit conversions | Adapt `units`; add `unit_conversions` CRUD | Phase 0 | All inventory masters and item-specific unit mappings |
| **Complete:** Domain categories | New raw-material, part and finished-product categories | None | Masters and reports |
| **Complete:** Raw-material master | New `raw_materials`; identity/classification only | Units, category, brand | Purchase, part production, assembly |
| **Complete:** Part master | New `parts` | Units, category, brand | Purchase, part production, product assembly |
| **Complete:** Finished-product master | New `finished_products` | Units, category, brand | Product assembly, POS/wholesale |
| **Complete:** Domain unit mappings | New per-domain unit mapping | Units + domain master | Purchase, BOM, production, sale |
| **Complete:** Material/part supplier setup | New typed supplier mappings with separate routes/RBAC | Supplier + raw material/part | Purchase, supply planning |
| **Complete:** Supplier/customer/brand retained master foundation | Retain/adapt existing masters | Phase 0 | Purchase/sales |
| **Complete:** Reorder-level setup | Three domain-specific tables with separate routes/RBAC | Item + warehouse | Low-stock/planning |

**Screen flow**

`Category → Unit → Warehouse → Item → Item Unit/Supplier → Reorder Level`

**Acceptance test**

1. Create a raw material (including a packaging category), purchased part, make-or-buy part and finished product in their separate masters.
2. Verify sale search can query only the finished-product master.
3. Verify a part with `BOTH` is one part record, not two records.
4. Verify each active warehouse has correct type and branch relation.

### Phase 2 — Inventory core, opening stock, transfer and adjustment — Complete (CSV import and Physical Count deferred)

**Why before purchase/production/sales:** This phase establishes the single source of stock truth.

| Build / adapt | Reuse or new | Depends on | Connects to |
| --- | --- | --- | --- |
| **Complete:** Raw-material stock posting service | New core service | Raw material + warehouse | Purchase, production/assembly consumption, transfer, adjustment |
| **Complete:** Part stock posting service | New core service | Part + warehouse | Purchase, production output, assembly consumption, transfer, adjustment |
| **Complete:** Finished-product stock posting service | New core service | Finished product + warehouse | Assembly output, sale, return, transfer, adjustment |
| **Complete:** Three movement ledgers and balances | New | Domain posting services | Audit, availability and reporting |
| **Complete (manual):** Opening stock document with approval/posting | New controlled flow | Ledger + unit mappings | Go-live stock |
| **Deferred/optional:** Opening stock CSV import | New controlled input | Manual opening workflow | Bulk go-live stock entry only if required |
| **Complete:** Stock Transfer | New controlled flow | Ledger + balance | Branch/showroom replenishment |
| **Complete:** Manual Stock Adjustment | New controlled flow | Ledger + balance | Gain, loss, damage, wastage and correction |
| **Deferred/optional:** Physical Count | New count snapshot flow | Balance + adjustment | Counted-vs-system difference and approved correction |
| **Complete:** Stock Inquiry / Movement Ledger | New query/UI | Ledger + balance | Operations/reporting |

**Mandatory service behaviour**

- DB transaction and row locking are used when stock is posted.
- One document line cannot create duplicate movement when the request is retried.
- Available stock cannot become negative unless a specifically approved policy later permits it.
- Posted movement never changes; reversal movement is used for correction.
- Balance is updated with the ledger in the same transaction.

**Acceptance test**

1. Add opening stock for raw material, part and finished good.
2. Transfer stock between two warehouses; verify source OUT, destination IN and both balances.
3. Post adjustment with reason and approval; verify ledger history is unchanged except new adjustment row.
4. Simulate repeated request; verify duplicate stock movement is not created.

### Phase 3 — Purchasing and supplier receipt — Complete

**Why now:** Purchase becomes the first normal operational source of material/part stock.

| Build / adapt | Reuse or new | Depends on | Connects to |
| --- | --- | --- | --- |
| **Complete:** Optional purchase order | New mixed raw-material/part header-line workflow | Active supplier, purchasable item, purchase unit, warehouse; supplier mapping is optional metadata | Purchase receipt |
| **Complete:** Purchase receipt | New/adapt | Inventory posting service | Stock IN, finance draft, price history |
| **Complete:** Purchase return | New/adapt | Receipt + inventory | Stock OUT, finance draft |
| **Complete:** Purchase payment / supplier due | Rebuilt after finance review | Receipt + accounts | Finance |
| **Complete:** Purchase price history | New report/query | Receipt lines | Rate-sheet decision support |

**Screen flow**

`Optional Purchase Order → Direct/PO-based Purchase Receipt → Post Receipt → Stock IN + Draft Journal → Payment / Return if needed`

**Rules to enforce**

- Direct receipt must work without a purchase order.
- Receipt line only accepts purchasable raw material, packaging or part.
- Actual supplier unit rate remains immutable after posting.
- Receipt status `DRAFT` has no stock effect; `POSTED` creates stock IN once.

**Acceptance test**

1. Directly receive a raw material without PO.
2. Receive part against PO partially, then receive remaining quantity.
3. Verify received quantity, inventory ledger, balance and draft journal.
4. Return a posted receipt line with valid available quantity.

### Phase 4 — BOM, internal part manufacture and finished-product assembly — In progress

**Why after purchase:** BOM/production requires reliable available component stock.

| Build / adapt | Reuse or new | Depends on | Connects to |
| --- | --- | --- | --- |
| **Complete:** Part BOM header/version | New | Make/Both Part + output unit | Production, planning |
| **Complete:** Part BOM component lines | New | Raw Material + production unit mapping | Material calculation |
| **Complete:** Part Production batch | New | Active BOM + source/output warehouse | Inventory, QC, report-only costing |
| **Complete:** Actual consumption | New | Inventory service | Component stock OUT |
| **Complete:** Production cost recording | New | Cost category + batch | Report-only material/other/unit cost; no payment workflow |
| **Complete:** Basic QC / reject record | New fields/workflow | Production batch | Wastage reports |
| **Complete:** Production completion | New action | Consumption + QC | Output stock IN + finance draft |
| Next: Finished Product BOM | New | Finished Product + mixed Part/Raw inputs | Final assembly |
| Pending: Final Product Assembly | New | Active Product BOM + component stock | Finished Product stock + finance |

**Screen flow**

`BOM Create/Activate → Production Batch Draft → Load BOM → Confirm Actual Consumption → Add Direct Expense → Enter Good/Reject Qty → Complete Production`

**Important business coverage**

- A `PART` with procurement mode `MAKE` or `BOTH` can be production output, then later BOM component.
- A bought `PART` and internally made `PART` use the same item record and same part stock.
- Finished good is production output; it alone is available in sales search.
- Basic QC records good/reject quantity and reason; no rework work order in release 1.

**Acceptance test**

1. Manufacture a part from raw materials.
2. Assemble a finished product using the internally made or purchased part.
3. Verify all consumption OUT and output IN entries occur atomically.
4. Verify insufficient component stock blocks completion.
5. Verify reject/wastage is visible in production history and cost report.

### Phase 5 — Finished-product sales, POS, wholesale and returns — Pending

**Why after production:** Sales must read the new finished-good stock rather than legacy product stock.

| Build / adapt | Reuse or new | Depends on | Connects to |
| --- | --- | --- | --- |
| Finished-good search/API | Adapt existing POS search | Item + inventory balance | POS/wholesale screen |
| POS sale | Adapt existing POS UX | New stock service | Inventory + payment + journal |
| Wholesale sale | Adapt sales invoice pattern | New stock service | Inventory + customer due |
| Payment/due | Adapt existing payment logic | Accounts/customer | Finance |
| Sale return | Adapt existing return pattern | Sale + inventory | Stock IN/refund |
| Invoice/print | Reuse print layout patterns | Sale data | Operations |

**Rules to enforce**

- Item search returns only active, sellable `FINISHED_GOOD`.
- Branch/showroom sale deducts its configured issue warehouse stock.
- POS and wholesale share one sale engine; only price/payment/customer flow differs.
- Sale completion posts stock OUT once and creates a finance draft journal.
- Coupon, offer and loyalty logic remain disabled in this phase.

**Acceptance test**

1. Attempt to sell raw material/part; system must reject it.
2. Sell a finished item from showroom warehouse; verify stock and payment.
3. Create wholesale invoice with due amount.
4. Return a finished item; verify return warehouse/condition and stock movement.

### Phase 6 — Finance automation and reconciliation — Partially complete

**Why after core documents:** Journal automation can only be tested correctly against real document posting.

| Build / adapt | Reuse or new | Depends on | Connects to |
| --- | --- | --- | --- |
| **Complete:** Journal draft generator | Rebuilt and verified | Purchase, production, sale, expense | Finance |
| **Complete:** Journal review/post/void/reversal screen | Rebuilt | Journal entries/lines | Accountant workflow |
| **Complete (Purchase):** Event-account mapping | Company default + branch override mapping | Accounts | Purchase automation rules |
| **Complete (Supplier):** Payable summary | New supplier due summary | Purchase/payment | Finance reports |
| Pending: Receivable summary, sales mapping and stock/accounting reconciliation | New report/service | Sales + production documents | Month-end control |

**Initial automated draft events**

| Event | Expected accounting result (subject to account mapping approval) |
| --- | --- |
| Posted purchase receipt | Inventory/stock debit; supplier payable or cash/bank credit |
| Posted purchase return | Supplier payable/cash debit; inventory credit |
| Completed production batch | No journal; only Raw Material stock OUT, good Part stock IN and report-cost snapshots |
| Actual salary/power/rent/transport payment | General Expense journal: selected Expense debit; Cash/Bank credit |
| Completed production | WIP/output inventory reclassification according to approved policy |
| Completed sale | Cash/receivable debit; sales income credit; inventory/COGS entry as approved |
| Completed sale return | Reversal/return entry according to approved policy |

**Acceptance test**

- Each draft journal balances: total debit = total credit.
- Only authorised accountant can post or void.
- Void does not silently alter original document/stock; it follows approved correction flow.
- Reconciliation highlights any posted stock document lacking a draft journal.

### Phase 7 — Monthly rate sheet, reports, print and supply planning — Pending

**Why after transactions:** These features depend on trustworthy purchase, production, stock and sales history.

| Build / adapt | Reuse or new | Depends on | Connects to |
| --- | --- | --- | --- |
| Reporting period open/close | New | Permissions | Rate sheet/report snapshots |
| Company-wide monthly rate sheet | New | Three inventory masters + purchase history | Valuation/reports/planning |
| Current stock valuation | New report | Balance + active monthly rates | Management dashboard |
| Monthly stock/purchase/production/sales reports | New queries, reuse print UI | All posted transactions + rates | Print/PDF |
| Frozen report snapshot | New | Reporting period + rate sheet | Historical report integrity |
| Demand plan | New | Sales history / manual target | MRP |
| Material requirement plan | New | Demand plan + BOM + stock + reorder level | Purchase recommendation |
| Low-stock report | New/adapt | Balance + reorder level | Operations |

**Monthly flow**

`Open Reporting Month → Prepare/Approve Company Rate Sheet → Operate Normally → Generate Reports → Close Month → Lock Rate + Final Report Snapshots`

**Acceptance test**

1. August rate/report stays unchanged after September/October rate update.
2. Current stock value uses active current-month rate.
3. A finished product made in August but sold in September appears in September report at September applicable rate.
4. Demand plan expands finished-good demand through active BOM and subtracts available stock/safety level.
5. Closed period blocks rate edit until authorised reopen.

### Phase 8 — Hardening, data migration, training and go-live — Pending

| Work item | Output |
| --- | --- |
| Permission/UAT checklist by user role | Signed acceptance by admin, purchaser, storekeeper, production user, cashier, accountant |
| Opening master/stock import | Approved opening balances with reconciliation |
| Migration rehearsal on copy of local/live data | Timed, repeatable cutover procedure |
| Performance and concurrency test | Stock posting remains correct under normal simultaneous use |
| Backup/restore and rollback plan | Go-live recovery procedure |
| Training materials | Short role-based operating guide |
| Pilot branch/showroom launch | Real transaction validation before wider rollout |

**Go-live gates**

- Stock movement, production completion and finished-good sale pass end-to-end test.
- No raw material/part is sellable through any POS/wholesale endpoint.
- Finance draft journal is balanced for approved core events.
- Month-close report snapshot is demonstrated with a test month.
- Opening balance/warehouse stock is signed off.

## 6. Dependency matrix — what must finish first

| Module | Cannot start properly before | Can start UI/design work in parallel? | Blocks |
| --- | --- | --- | --- |
| Three inventory masters | Final DB naming, units/categories | Yes | Purchase, BOM, sale, rates |
| Three stock movement cores | Domain masters + warehouse policy | Limited | Every stock-changing module |
| Opening stock/transfer/adjustment | Domain stock services | Yes | Go-live stock accuracy |
| Purchase receipt | Raw/part stock services + supplier | Yes | Normal material/part inflow |
| BOM | Domain masters + unit conversion | Yes | Production, planning |
| Production | BOM + inventory movement + expenses | Yes | Finished-good availability |
| POS/wholesale | Inventory movement + finished item + warehouse | Yes | Revenue operation |
| Finance draft | Event definitions + accounts + source documents | Mapping/UI yes | Financial control |
| Monthly rate/report | Posted transaction data + rate rules | Wireframe yes | Historical management reporting |
| Supply planning | BOM + balance + sales history/manual plan | Wireframe yes | Purchase recommendation |
| Offer/coupon/loyalty | Finished-item sales + customer/e-commerce scope | No need now | Future e-commerce only |

## 7. Recommended release grouping

| Release | Included phases | Business result |
| --- | --- | --- |
| **R1: Foundation** | 0–2 | New item/warehouse structure and auditable stock core are ready. |
| **R2: Buy and make** | 3–4 | Company can purchase materials/parts and manufacture/assemble items. |
| **R3: Sell and account** | 5–6 | Finished products can be sold through POS/wholesale with reviewed finance drafts. |
| **R4: Control and plan** | 7–8 | Monthly reporting, planning, training and controlled go-live are complete. |
| **Future R5** | E-commerce order flow, offer/coupon/loyalty activation, warranty/serial, advanced production | Only after separate requirement approval. |

## 8. Suggested team/role involvement per phase

| Business role | Required involvement |
| --- | --- |
| Owner/management | Approves item classes, pricing, rate policy, reporting and go-live scope. |
| Storekeeper/warehouse user | Verifies warehouse types, stock movement, transfer, adjustment and opening stock flows. |
| Purchase user | Verifies supplier, direct receipt, PO, actual rate and return flow. |
| Production supervisor | Verifies BOM, material consumption, QC/reject and direct expense flow. |
| Cashier/sales user | Verifies POS, wholesale, payment, due and finished-good-only rule. |
| Accountant | Approves account mapping, journal draft, posting/void and reconciliation rules. |
| Development team | Implements, migrates, tests permissions and maintains rollback plan. |

## 9. Current implementation gate

1. Load and verify the retained foundation data listed in Current Status.
2. Seed the clean core permission catalogue; do not copy legacy permission mappings.
3. Confirm the initial branch/warehouse list and the default issuing warehouse for each showroom.
4. Test retained access, branch, warehouse, company, location and master-data screens.
5. **Complete:** legacy dashboard queries were replaced with the clean-schema zero state.
6. **Complete:** route-detached legacy controllers, models, services, jobs and Blade screens were removed after creating the recoverable Git baseline.
7. **Complete:** Units & Unit Conversion CRUD, reverse-factor display, duplicate-pair validation and reference-safe unit deletion.
8. **Complete:** three independent category modules with parent hierarchy, searchable Select2 parent selection, delete protection and separate RBAC mappings.
9. **Complete:** Raw Material Master with type/category/base-unit/brand classification, image support, filters, reference-safe deletion and separate RBAC mapping.
10. **Complete:** Part Master with `MAKE`/`BUY`/`BOTH` sourcing policy, image support, filters, reference-safe deletion and separate RBAC mapping.
11. **Complete:** Finished Product Master with default retail/wholesale prices, image support, filters, reference-safe deletion and separate RBAC mapping.
12. **Complete:** Domain unit mappings for Raw Material, Part and Finished Product with item-specific conversion factors and process usage rules.
13. **Complete:** Raw-material/part supplier mappings with approved supplier code, lead time and single preferred source rules.
14. **Complete:** Warehouse-wise Raw Material, Part and Finished Product reorder-level setup with independent permissions.
15. **Complete:** Phase 2 transaction-safe stock posting services, typed movement models and warehouse-wise current balances with idempotency, negative-stock protection and reversal support.
16. **Complete:** Manual Opening Stock document with mixed domain lines, unit conversion and controlled `DRAFT → APPROVED → POSTED` flow.
17. **Complete:** Stock Transfer with mixed-domain lines, mapped-unit conversion and atomic source OUT/destination IN posting.
18. **Complete:** Manual Stock Adjustment with mixed-domain lines, type-controlled direction, mapped-unit conversion and `DRAFT → APPROVED → POSTED` workflow.
19. **Deferred/optional:** Physical Count with system/count snapshot and approved difference posting; implement later when periodic warehouse audit is required.
20. **Complete:** Stock Inquiry / Movement Ledger over the three domain movement tables with cross-domain filters, source-document links and true chronological running balance.
21. **Complete:** Phase 3 Purchase Order with mixed Raw Material/Part lines, open purchasing (optional mapping badge/preference), business-rule item eligibility, purchase-unit conversion and controlled `DRAFT → APPROVED/CANCELLED` flow. It has no stock or finance effect.
22. **Complete:** Direct/PO-based Purchase Receipt with a 50/50 hybrid catalogue/form UI, pending-only PO receiving context, actual supplier-rate snapshot, partial/full PO progress, server-controlled totals, over-receipt protection and idempotent Raw Material/Part stock IN posting.
23. **Complete:** Purchase Return against posted receipts with receipt-controlled supplier/warehouse/item/unit/rate, partial and cumulative return validation, and atomic idempotent Raw Material/Part stock OUT posting.
24. **Complete:** Journal-safe Finance Foundation and purchase Account Mapping with typed starter Chart of Accounts, company defaults, branch overrides, compatibility validation and independent permission.
25. **Complete:** Generic Balanced Draft Journal service with active fiscal-year/branch-account validation, exact four-decimal balancing, deterministic voucher sequencing and durable posting/source idempotency.
26. **Complete:** Posted Purchase Receipt/Return events create balanced, retry-idempotent Draft Journals inside the stock transaction. A reconciliation command creates missing drafts for existing posted purchase documents without duplication.
27. **Complete:** Add a branch-scoped Journal List/Details UI with independent permission, status/source filters, source-document navigation and account-wise debit/credit verification.
28. **Complete:** Implement the controlled Journal workflow: balanced Draft approval, revalidation and posting, Draft-only void with audit reason, and one linked debit/credit-swapped reversal Draft for every posted correction.
29. **Complete:** Build Account Ledger with branch/account/date filters, Posted journal plus Posted opening-balance history, source-journal navigation and chronological Dr/Cr running balance.
30. **Complete:** Build receipt-specific Supplier Payment/Due, payment correction/reversal control, Supplier Due Summary and Purchase Price History. Opening Stock CSV import remains deferred.
31. **Complete:** Build balanced Finance Opening Balance over existing account-opening rows with one Fiscal Year + Branch scope, active assigned-BDT account guard, exact Debit/Credit check, editable Draft and immutable Posted ledger effect.
32. **Complete:** Add explicit Cash/Bank account eligibility, restrict Supplier Payment sources accordingly and build a read-only Cash/Bank Book from Posted opening/journal movements with running balance.
33. **Complete:** Add idempotent starter Owner/Partner Capital accounts and controlled branch-scoped Owner/Partner Capital Investment documents. Posting creates a reviewable Receipt Voucher Draft (Debit Cash/Bank, Credit Equity); journal posting remains accountant-controlled.
34. **Complete:** Add idempotent Director / Partner Loan Liability account and controlled branch-scoped Loan Receipt document. Posting creates a reviewable Receipt Voucher Draft (Debit Cash/Bank, Credit Liability).
35. **Complete:** Add Capital Withdrawal (Debit Drawings, Credit Cash/Bank) and same-branch Cash/Bank Transfer (Debit destination, Credit source), each with a reviewable Draft Journal.
36. **Complete:** Build Loan Repayment against a posted received loan with outstanding-principal validation, Cash/Bank source eligibility and a reviewable Draft Journal (Debit Loan Liability, Credit Cash/Bank).
37. **Complete:** Build branch-scoped Trial Balance from Posted opening balances and Posted journal lines, with as-of date filter, debit/credit totals, closing Dr/Cr positions and explicit balanced status.
38. **Complete:** Build controlled Fiscal Year Closing for a branch Drawings-to-Capital adjustment. It server-calculates the Posted balance at the active fiscal-year end, creates a reviewable balanced Closing Journal Draft and blocks next-year activation until that Journal is posted.
39. **Complete:** Finance hardening audit—fix All Branches report filtering, enforce Loan Repayment branch isolation, preserve exact Cash/Bank transfer decimals, protect source-generated journals from detached voids, and block fiscal-year switching while Draft journals remain.
40. **Complete:** Finance code-quality pass—refactor compressed routes, models, controllers, services and Blade markup; introduce reusable view partials and an automated readability guard for the complete Finance scope.
41. **Complete:** Build Part BOM / Recipe Master for Make/Both Parts with versioning, Draft/Active control, raw-material production-unit validation, base-quantity snapshot and one-active-version policy.
42. **Complete:** Build Internal Part Production Batch using the active Part BOM, actual consumption, basic QC, report-only cost recording and atomic stock posting without a Finance journal.
43. **Complete:** Build Production Cost Categories without per-category ledger-account duplication.
44. **Superseded:** Production Expense Payable and production-cost settlement were removed on 12 November 2026. Actual costs are paid once through General Expense; batch cost remains for operational reporting only.
45. **Next implementation:** Build Finished Product BOM with mixed Part and Raw Material components.

Finance account mapping, the generic Draft Journal engine and the authorised accountant workflow are complete. Purchase integration creates reviewable drafts; only approved, successfully revalidated journals can be posted.

## 10. Out of current development roadmap

- Public e-commerce checkout/order/delivery/payment integration.
- Activating offer, coupon or loyalty screens/rules; their foundation tables are only created for future use.
- Warranty, warranty return, repair service, serial/IMEI tracking.
- Full subcontracting and multi-stage manufacturing planning.
- AI/seasonal forecast and automatic purchase-order creation.

## 11. Related documents

- [Project Plan](ELECTRONICS_POS_PROJECT_PLAN.md)
- [Physical DB Design](ELECTRONICS_POS_PHYSICAL_DB_DESIGN.md)
- [Current Status and Next Actions](ELECTRONICS_POS_CURRENT_STATUS.md)

