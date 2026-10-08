# Electronics Manufacturing, Inventory & Sales System — Project Plan

**Status:** Business scope approved; clean database baseline implemented  
**Version:** 1.0  
**Updated:** 8 October 2026  
**Purpose:** This is a discussion and approval document. It does not approve database changes or implementation yet.

## 1. Project objective

Build a simple, branch-aware system for an electronics company that can:

- purchase raw materials and externally sourced parts;
- manufacture parts internally when required;
- assemble parts into finished products;
- sell only completed/assembled finished products through POS or wholesale;
- maintain accurate warehouse stock, stock movement history, finance entries, and monthly reports;
- estimate next month's material and parts requirement.

The priority is an easy operational flow for staff. This project is **not** intended to begin as a complex ERP or factory-planning system.

## 2. Confirmed business understanding

```text
Raw Material Purchase
       ↓
Raw Material Stock
       ↓
Internal Part Production OR External Part Purchase
       ↓
Part Stock (assembly input only)
       ↓
Finished Product Assembly
       ↓
Finished Product Stock
       ↓
POS / Wholesale Sale
```

### 2.1 Three separate inventory domains

The final meeting decision replaces the earlier unified-item proposal. The system will use three explicit masters and three stock domains:

| Domain | Master | Stock ledger/balance | Purchase | Manufacture | Sale |
| --- | --- | --- | --- | --- | --- |
| Raw material | `raw_materials` | Raw-material ledger and balance | Yes | No | No |
| Part | `parts` | Part ledger and balance | Yes | Yes (`MAKE`, `BUY`, or `BOTH`) | No |
| Finished product | `finished_products` | Finished-product ledger and balance | No in release 1 | Assembly only | Yes |

Packaging and consumables are categories within the raw-material domain, so the agreed three-domain design remains intact. A part that can be made and purchased remains one `parts` record; its source is recorded by purchase receipts or part-production batches. Only completed finished products can appear in POS or wholesale sales.

### 2.2 Stock principle

Every quantity change must create an entry in the appropriate domain ledger. Each domain has its own current-stock balance table for clarity and FK integrity; the ledger remains the audit source.

| Business event | Stock effect |
| --- | --- |
| Purchase receipt | IN |
| Purchase return | OUT |
| Internal part production | Input items OUT; produced part IN |
| Finished-product assembly | Components OUT; finished product IN |
| POS / wholesale sale | OUT |
| Customer return | IN after acceptance |
| Warehouse transfer | OUT from source; IN to destination |
| Adjustment / damage / wastage | Separate, documented movement |

### 2.3 Month-based report rate and stock valuation principle

The business requires a **month-specific management rate**, not a report cost determined by the month in which a product was manufactured. A product made in August but sold in September is valued in the September sales report using the September rate.

Two price records must remain separate:

1. **Actual purchase receipt price** — the supplier invoice/receipt rate paid on that date. It is retained for purchase history, supplier payable, and accounting audit.
2. **Monthly report rate** — the approved current rate for each material, part, and finished product for a specific reporting month. It is used for monthly cost/profit reporting, BOM-based valuation, supply planning, and current stock valuation.

The monthly reporting rules are:

| Rule | Required behaviour |
| --- | --- |
| August report | Uses August approved rates and remains unchanged after August is closed. |
| September sales report | Uses September approved rates, even if the finished product was produced in August. |
| Price update in October | Affects October/current valuation only; it must not recalculate closed August or September reports. |
| Current stock value | Uses the active rate of the current month for all on-hand raw materials, parts, and finished products. |
| Finished-product monthly value | Is calculated from its BOM and the applicable month's input/part rates, plus approved monthly production-cost rules. |

Raw-material purchases and rate changes are expected to occur infrequently—normally after one or two months—so staff will usually maintain one active rate per item for a month. For the rare mid-month change, an authorised user may revise the open month's rate. On period close, the rate sheet and generated monthly reports are locked as a snapshot. This avoids historical reports changing after a later price update.

The meeting must still confirm who may update a monthly rate and who may close/reopen a month. The first release may treat a rare mid-month revision as the active rate for the open reporting month; date-effective intramonth pricing can be added later if required.

## 3. Scope for the first release

### 3.1 Included

- User, role, permission and branch-aware access.
- Multiple branches, showrooms, and warehouses.
- Separate raw-material, part and finished-product masters; their categories, brands, units, conversions, suppliers, customers, and payment types.
- Purchase of raw materials and parts.
- Warehouse stock, transfer, adjustment, opening stock, low-stock level, and ledger.
- Sale of completed finished products by POS and wholesale invoice.
- Simple BOM / recipe and production or assembly batch.
- Production input consumption, output quantity, wastage, and direct production expenses.
- Basic accounts: cash/bank, purchase payment, sale payment, expense, voucher/journal, due summary.
- Monthly stock, purchase-price, production, sales, cost, and low-stock reports with print-friendly layouts.
- Initial next-month material/parts requirement estimate based on sales history or a manually entered target.

### 3.2 Deliberately deferred

- Warranty, warranty return, repair centre, and serial/IMEI tracking.
- Advanced supplier subcontracting workflow where company-owned materials are issued to a third party.
- Sophisticated forecasting, seasonal AI prediction, or automatic purchase ordering.
- Multi-stage factory scheduling, work-centres, machine capacity, or shift planning.
- Public e-commerce storefront, online-order flow, campaign/loyalty/coupon screens, and unrelated legacy retail features.

Deferring these operational features keeps the first release understandable and usable. Foundation tables for future offers, coupons, and loyalty will be created now but remain inactive; no discount or point logic will run until the later e-commerce scope is approved. The data structure will also be planned so warranty, serial numbers, and subcontracting can be added later.

## 4. Simple operational flows

### 4.1 Purchase flow

1. User selects supplier, warehouse, and items.
2. User enters received quantity, unit, unit cost, invoice/reference, and date.
3. System posts stock IN, updates payable/payment information, and preserves the receipt cost.
4. A price-history report shows price changes by item and date.

### 4.2 Production / assembly flow

1. User selects the part or finished product to produce and enters output quantity.
2. System loads its BOM and calculates required input quantities.
3. User confirms or adjusts actual consumed quantities and adds direct expenses such as labour, packing, electricity, or transport.
4. User records wastage/reject quantity if applicable.
5. On completion, the system posts input stock OUT, output stock IN, and stores the actual batch cost.

The first version will not require a complex work-order approval chain. A single “Complete Production” action, protected by permission, is the intended operating model.

### 4.3 Sales flow

One sale module will support both retail POS and wholesale:

- Sale type: `POS` or `WHOLESALE`.
- Only completed finished products marked sellable appear in sales search.
- Finished-product sale reduces warehouse stock through the ledger.
- Payment, due, return, invoice, and branch/showroom context are shared.

### 4.4 Supply planning flow

1. Choose planning month and branch/warehouse.
2. Use past sales and/or a manually entered demand target for finished products.
3. Expand demand through the BOM to calculate material/part requirement.
4. Subtract usable on-hand stock and confirmed incoming purchase quantity.
5. Add minimum/safety stock if configured.
6. Show suggested purchase quantity and estimated purchase value.

The first release produces a recommendation report only. It will not automatically create purchase orders.

## 5. Existing system reuse assessment

This is a planning-level assessment based on the current codebase. Before implementation, each candidate module will receive a focused technical and business-flow audit.

| Current area | Initial decision | Planned treatment |
| --- | --- | --- |
| Login, users, roles, permissions, route permissions | Retain | Keep as the security foundation; add permissions for new modules. |
| Branch and branch switching | Retain / verify | Keep for multiple branches; clarify showroom and warehouse relationships. |
| Units and unit conversion | Retain / light adaptation | Reuse as shared measurement foundation for all three inventory domains. |
| Warehouses | Retain / light adaptation | Keep branch-owned warehouse foundation; new stock ledgers will reference it. |
| Stock ledger and current-stock summary | Rebuild | Create separate raw-material, part and finished-product ledgers/balances. Do not extend the legacy product stock core. |
| Suppliers, customers, payment types | Retain / light adaptation | Keep master-data modules and streamline screens. |
| Purchase order, receipt, payment, return | Rebuild on verified document patterns | Purchase lines may reference raw materials or parts only; preserve actual receipt price. |
| Legacy product catalogue and variants | Replace | Create a dedicated finished-product master. Raw materials and parts have their own masters. |
| POS, sale, payment, return | Rebuild / simplify | One sales engine for POS and wholesale; sale lines reference finished products only. |
| Expense module | Adapt | Categorize direct production expenses separately from admin/selling expenses. |
| Accounts, vouchers, journals, fiscal year | Retain / verify | Keep existing foundation only after validating posting rules; integrate in phases. |
| Reports and print views | Adapt | Build new reports on the new stock and production records; use existing print conventions where suitable. |
| Offers, coupons, loyalty, public e-commerce modules | Data foundation now; workflow deferred | Create inactive future-use tables linked to finished items/customers; do not enable screens, automatic discounts, or points in the first release. |

## 6. Proposed functional modules

1. **Administration & access** — users, roles, permissions, branches.
2. **Master data** — separate raw materials, parts and finished products; categories, brands, units, conversions, suppliers, customers, warehouses and expense types.
3. **Purchasing** — order/receipt, supplier invoice, payment, return, purchase-price history.
4. **Inventory** — opening stock, ledger, stock balance, transfer, adjustment, damage/wastage, low-stock report.
5. **BOM & production** — BOM, internal part production, finished-product assembly, batch cost, material consumption.
6. **Sales** — finished-product POS, wholesale invoice, payment/due, return, stock availability.
7. **Finance** — accounts, vouchers/journal, cash/bank, expense, receivable/payable summaries.
8. **Planning & reports** — monthly reports, printable reports, demand estimate, material requirement recommendation.

## 7. Finance integration rule

Direct production cost and general expense must not be mixed.

| Cost type | Treatment |
| --- | --- |
| Purchased material / part | Preserves actual receipt value for purchase/accounting; monthly management reports use the applicable monthly report rate. |
| Production labour, packing, production electricity, production transport | May be allocated to the completed production batch. |
| Office rent, admin salary, marketing, general utility | Remains a period expense; does not automatically inflate product cost. |

The final accounting workflow must specify which events automatically create journals and which require an accountant to post/approve a voucher.

## 8. Key decisions needed in the requirement meeting

| Decision | Why it matters | Initial recommendation |
| --- | --- | --- |
| Actual inventory-cost method | Needed for accounting and purchase reconciliation; it remains separate from the monthly report/stock-value rate. | Weighted average initially. |
| Monthly report-rate policy | Determines how closed-month reports remain unchanged despite later price updates. | Rate changes normally occur after 1–2 months; one approved rate sheet per open month, editable by authority and locked at month close. |
| Branch/showroom/warehouse model | Defines stock ownership and transfer rules. | Branch contains one or more warehouses/showrooms. |
| BOM depth | Determines whether a part can itself have a BOM. | Support it in design; start simple where possible. |
| Production approval | Controls who can post irreversible stock movements. | Draft then permission-controlled completion. |
| Wastage/reject treatment | Needed for real production cost and stock accuracy. | Record quantity, reason, and cost impact. |
| Sale-price policy | Determines POS and wholesale selling prices. | Separate retail and wholesale price fields/rules. |
| Minimum stock | Defines low-stock alerts and planning baseline. | Optional per item per warehouse. |
| Opening data | Defines what is imported from the current system. | Import users/RBAC and selected master data; business transactions only after approval. |
| Accounting automation | Avoids incorrect financial entries. | Start with reviewed core entries, then expand. |

## 9. Risks and controls

| Risk | Control |
| --- | --- |
| Same part is created multiple times for purchase and manufacture | One `parts` record with source mode `MAKE`, `BUY`, or `BOTH`; direct part sale is disabled. |
| Stock is edited directly without trace | Ledger-only stock posting; adjustments require reason and permission. |
| Historic reports change after a later price update | Keep actual receipt prices separately and lock the approved monthly report-rate snapshot at period close. |
| Production flow becomes too difficult for operators | Keep first version to BOM → actual input → expense → complete output. |
| Existing modules are reused without validation | Audit each reuse candidate before implementation. |
| Finance and stock diverge | Define posting events and reconciliation reports before automation. |

## 10. Project sequence

1. **Complete:** requirements, reuse boundary and database design approved.
2. **Complete:** clean migrations created and run on the new database.
3. **Current:** load foundation data, generate clean permissions and test retained modules.
4. **Next:** implement in small, testable releases: master data → purchasing/inventory → production → sales/finance → reports/planning.
5. **Before go-live:** run branch/warehouse stock, cost, finance and permission acceptance tests.

## 11. Approved baseline checklist

- [x] Scope of the first release
- [x] Three separate inventory masters and stock domains
- [x] Simplified production flow
- [x] Branch/showroom/warehouse relationship
- [x] Cost valuation and monthly rate policy
- [x] Direct production expense policy
- [x] Finished-product-only POS and wholesale sale policy
- [x] Minimum stock and planning scope
- [x] Deferred warranty/serial/subcontracting scope
- [x] Existing-module retain/rebuild decisions

## 12. Supporting design artefacts

- [Physical Database Design](ELECTRONICS_POS_PHYSICAL_DB_DESIGN.md)
- [Development Roadmap](ELECTRONICS_POS_DEVELOPMENT_ROADMAP.md)
- [Current Status and Next Actions](ELECTRONICS_POS_CURRENT_STATUS.md)

These four documents, including this plan, are the authoritative project set. The physical baseline has been migrated to the new database; later schema changes must use new forward migrations.

## 13. Change log

| Version | Date | Change |
| --- | --- | --- |
| 0.1 | 24 September 2026 | Initial meeting draft based on current requirements and existing-system review. |
| 0.2 | 24 September 2026 | Direct part sale removed; make-or-buy parts retained; month-based report-rate and current-stock valuation rules added. |
| 0.3 | 24 September 2026 | Monthly rate changes clarified as normally occurring every 1–2 months, with rare open-month revisions supported. |
| 0.4 | 27 September 2026 | Added final reuse-audit draft and conceptual data-model/ERD draft for database-design review. |
| 0.5 | 27 September 2026 | Confirmed company-wide rate, direct receipt, automatic finance drafts, basic QC, and retail/wholesale price rule; added physical DB-design draft. |
| 0.6 | 27 September 2026 | Added full meeting table dictionary with proposed singular purchase/item table naming and table-level column/rule/constraint discussion points. |
| 0.7 | 27 September 2026 | Added inactive future e-commerce offer, coupon, and loyalty table foundation; operational promotion/e-commerce scope remains deferred. |
| 0.8 | 28 September 2026 | Added phase-based development roadmap, module dependency map, acceptance checkpoints and release grouping. |
| 0.9 | 7 October 2026 | Replaced unified item design with separate raw-material, part and finished-product masters/stock ledgers; packaging classified under raw materials; recorded the current panel-cleanup boundary. |
| 1.0 | 8 October 2026 | Confirmed the clean 113-table database baseline, consolidated documentation and moved the project into foundation-data setup and module development. |
