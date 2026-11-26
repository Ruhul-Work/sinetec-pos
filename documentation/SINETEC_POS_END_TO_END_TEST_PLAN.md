# Sinetec POS — End-to-End Acceptance Test

25 November 2026 · Run in staging

The automated scenario uses Laravel's in-memory SQLite test database. It runs the actual posting and reporting services without writing to the working database. The manual walkthrough below repeats that scenario in the staging UI. Mark each step Pass or Fail and record its document number before moving on.

## 1. Prepare the sample

Use an isolated staging database or a clean staging branch and warehouse. Start with zero opening stock and zero opening finance balances for this scenario. Use one open transaction month and the next open month for Demand Planning. The automated dates are 25 November 2026 for transactions, December 2026 for demand and 1 December 2026 for closing November. For an immediate manual month-close test, use a completed staging month that is still Open and covered by the active fiscal year. Use matching dates across all documents. See the [User Setup Guide](SINETEC_POS_USER_GUIDE.md) for initial setup.

| Setup | Sample |
| --- | --- |
| Branch / default warehouse | `UAT-BR` Test Branch / `UAT-WH` Test Warehouse |
| Supplier / registered customer | `UAT-SUP` Test Supplier / `UAT-CUS` Test Customer |
| Base unit | PCS for all three items. Create one category per item type. |
| Raw Material | `UAT-RM-01` Test Blank. Add Test Supplier as its preferred supplier. |
| Part | `UAT-PT-01` Test Bracket, sourcing **Make** |
| Finished Product | `UAT-FG-01` Test Control Box, retail ৳100 and wholesale ৳90 |
| Active Part Recipe V1 | Output 1 Test Bracket from 1 Test Blank |
| Active Finished Product Recipe V1 | Output 1 Test Control Box from 2 Test Blanks plus 1 Test Bracket |
| Recipe dates and costing | Effective from the transaction date, end date blank, wastage 0%, no added batch expense in this sample |
| Payment method | Cash → `1000` Cash in Hand, active and assigned to the test branch |
| Finance Account Mapping | Verify the branch's effective assignments. Starter codes for this sample are Raw Material Inventory `1100`, Part Inventory `1110`, Input Tax `1200`, Supplier Payable `2000`, Purchase Discount `4100`, Customer Receivable `1210`, Output Tax `2200` and Sales Revenue `4000`. Save missing purchase mappings before posting. |

Use zero tax and discount on the PO, receipt and sale. In a fresh installation, the Finance seeder supplies starter accounts and three sales defaults. Configure the purchase mappings in **Finance → Account Mapping** before the first receipt.

## 2. Test the document chain

| Step | What to enter | Expected result |
| --- | --- | --- |
| 1. Demand Plan → MRP | Plan **5** Test Control Boxes in the next month with safety **0**. Approve the demand plan, then generate and approve MRP. | Net to assemble **5**. Make **5** brackets, buy **10** direct blanks. Verify the supplier recommendation. Stock and Finance remain at their opening values. |
| 2. Purchase Order | Open the recommended supplier's prefilled PO. Verify the MRP reference, supplier, warehouse and 10 blanks. Enter **৳25 per PCS**, save and approve. | PO total **৳250**. Raw stock stays **0** until receipt. |
| 3. Purchase Receipt | Create against the approved PO for the full 10 PCS at ৳25. Post the receipt. In Finance, approve and post its generated Draft Journal. | PO **Received**, raw stock **10**, supplier due **৳250**. Purchase journal debits Raw Material Inventory and credits Supplier Payable by **৳250**. |
| 4. Part Production | Load Part Recipe V1, consume **4** blanks and complete **4 good** brackets with zero reject and QC Passed. | Raw **6**, Part **4**. Stock Ledger shows raw OUT and part IN. Batch completion produces no Finance journal. |
| 5. Final Product Assembly | Load Finished Product Recipe V1, consume **4** blanks and **2** brackets. Complete **2 good** control boxes with zero reject and QC Passed. | Raw **2**, Part **2**, Finished **2**. Stock Ledger shows two inputs OUT and finished output IN. |
| 6. POS Sale | Select Test Customer. Sell **2** boxes at **৳100** each, enter Cash **৳120** and use Payment/Review. Then post the reviewed sale. | Stock stays **2** in review, then falls to **0** on posting. Bill **৳200**, received **৳120**, due **৳80**. Verify the posted sales journal and thermal receipt preview. |
| 7. Customer Due Collection | Collect the remaining **৳80** via Cash, dated after the sale. | Customer due **৳0**. Total collections on that sale before the return **৳200**. |
| 8. Supplier Payment | Record and post **৳100** against the same purchase receipt using Cash. Approve and post its generated Draft Journal. | Supplier due **৳150**. Cash before the sales refund is **৳100**. |
| 9. Sales Return | Return **1** box from the original invoice after the due was collected. Refund **৳100** via Cash and post. | Finished stock **1**, refund **৳100**, customer due **৳0**, net cash from the sale **৳100**. With the supplier payment included, closing branch cash is **৳0**. |
| 10. Reconcile | Filter Stock Ledger, Supplier Due Summary, Customer Receivables and Trial Balance by the test branch and transaction date. | Raw **2**, Part **2**, Finished **1**. Supplier due **৳150** and customer due **৳0**. Five Posted journals. Trial Balance debit and credit movement totals are each **৳730** on the isolated branch. |
| 11. Month close | In the transaction month set manual rates **৳25** raw, **৳25** part and **৳75** finished. Approve and lock the company rate sheet. After month-end, close the period with a reason and generate the branch snapshot. | Six frozen report sections. Stock valuation **৳175**, purchase receipts **৳250**, supplier payments **৳100**, good parts **4**, good finished units **2**, net sales **৳100**, net collected **৳100**, customer due **৳0**. Verify the snapshot checksum. |

**MRP boundary to review:** The current plan explodes the Finished Product Recipe only. In step 1 it recommends clearing the direct shortage of 10 blanks and making 5 brackets. Making all 5 brackets would also consume 5 blanks from the Part Recipe. A plan for all 5 finished units therefore needs **15 raw blanks** in this example. Include that extra part-production need separately during planning. This sample deliberately runs a smaller actual batch using the 10 received blanks. Confirm whether recursive Part BOM expansion is required for the release.

## 3. Manual checks after the main chain

- Test with an ordinary branch user and a supervisor who has multiple module permissions. Confirm menu visibility, POS shortcut permissions, and protection against reading or posting documents from a different branch.
- Check Low-Stock Alerts at, below and above the selected warehouse's minimum. Run direct receipt, partial PO receipt, purchase return, wholesale, split payment and return-before-due-collection as separate scenarios to keep the baseline totals comparable.
- Repeat one posting request or refresh its confirmation. Confirm that its stock movement and journal are recorded once. Try insufficient stock and confirm that the transaction rolls back.
- Compare report filters, search and print in the browser, including the actual A4 and thermal devices. Check the frozen snapshot after a later rate change.
- Record each outcome with branch, role, document reference, expected result and actual result before staging sign-off.

## Automated baseline

Verified on 25 November 2026. The integrated scenario and all 493 tests in the full suite passed. The full suite recorded 126,895 assertions.

From `core/`:

```sh
php vendor/bin/phpunit --no-progress tests/Feature/OperationalEndToEndTest.php
```

The test creates setup data, completes the document chain and verifies the ending balances and snapshot in its own database. Staging browser checks, real-role sign-off, production database performance and parallel posting remain separate.
