# Sinetec POS — User Setup and Operations Guide

Updated 25 November 2026

Use the login address and account issued by your administrator. Staff accounts are created in **Administration → Users & Security → Users**. Complete setup in order. For day-to-day work, check the branch in the topbar and the warehouse on the document before entering quantities.

## 1. Set up the company and access

1. Under **Organization → Company & Locations**, enter the company name, address and logo, then create branches and their warehouses. Use unique codes and set the default active warehouse for each branch.
2. Under **Administration → Users & Security**, set up roles for purchase, stores, production, sales and Finance. Assign each user a home branch and the permissions needed for their work. Give **View** for menu access and **Add/Edit** for the relevant entry, approval or posting actions. Test a normal user login in each branch. Only Super Admin can switch to **All Branches**.
3. If address dropdowns are used, maintain **Organization → Address Setup**. Check one invoice preview after saving company details.

## 2. Enter master data and finance settings

| Order | Enter or verify | Example or rule |
| --- | --- | --- |
| 1 | **Master Setup → Units, Unit Conversions, Categories, Brands** | Set base units such as KG and PCS. Create categories separately for Raw Materials, Parts and Finished Products. |
| 2 | **Master Setup → Inventory Items** | Add Raw Materials first, then Parts, then Finished Products. Use stable item codes. Mark Parts as Make, Buy or Both. Only Finished Products are sold. Set the initial retail/wholesale selling prices on the Finished Product. |
| 3 | **Item Units, Business Contacts, Supplier Mappings and Reorder Levels** | Add item-specific purchase/production units and their base conversion. Create supplier and customer records. For a preferred supplier, save the mapping. Set minimum stock by item and warehouse where alerts are needed. |
| 4 | **Payment Types and Finance Management** | Set up Cash, Bank or MFS payment methods as needed. Verify the active fiscal year, branch accounts and cash/bank accounts. The fiscal year must cover the document dates. |
| 5 | **Finance → Account Mapping and expense categories** | Review company defaults and any branch overrides with the accountant. Check Raw Material/Part Inventory, Supplier Payable, Input Tax, Purchase Discount, Customer Receivable, Sales Revenue and Output Tax. Set the general expense accounts used for actual payments. |

The starting accounts and some cost categories may already be present from deployment. Confirm them before posting. Final Product barcodes and labels can be generated after the product master is ready.

## 3. Bring in opening balances

1. **Inventory → Stock Operations → Opening Stock**. Select the branch, warehouse and opening date. Enter each item's quantity and cost, save Draft, approve and post. Reconcile the Stock Ledger and warehouse balances to the count sheet. Record opening quantity before normal movements for that item and warehouse.
2. **Finance → Opening Balance**. Enter the accountant-approved opening debit and credit lines for the branch and fiscal year, confirm both sides balance and post. Review Trial Balance and Cash/Bank Book before new payments. Confirm how old outstanding supplier/customer documents will be carried if they still need payment or collection.
3. After opening, use **Stock Transfer** for warehouse moves and **Stock Adjustment** for reviewed damage, wastage or corrections. Use the original document's return or authorized correction action for posted purchases/sales.

## 4. Run the main workflows

### Purchasing and supplier payments

1. Open **Inventory → Purchasing → Purchase Orders**. Pick the supplier, receiving warehouse, Raw Materials or bought Parts, unit, quantity and rate. Save Draft and approve after review. Use **Purchase Receipt** directly when no PO is needed.
2. Record the quantity actually received, verify price, discount and tax, then post the receipt. For a partial delivery, use the same PO for the remaining quantity later. Stock increases on receipt posting. Review and post the generated Purchase Draft Journal through the accountant workflow.
3. Use **Supplier Due Summary** and **Supplier Payments** to settle the receipt in full or in part. Choose the actual cash/bank account. Use **Purchase Returns** against the original posted receipt for rejected or returned items.

### Production and assembly

1. Create and activate a **Part Recipe** for each made part and a **Finished Product Recipe** for assembly. Confirm recipe version, validity, units, output quantity and component quantities.
2. For **Part Production**, select the valid recipe and source/output warehouse. Check actual raw-material use, report-only direct costs, good/rejected output and QC. Complete the batch to consume raw stock and add good parts. Bought and made parts share the same Part stock.
3. Use **Final Product Assembly** for the same workflow with Parts and Raw Materials as inputs. Completion adds good Finished Products to the selected warehouse. Batch costs support reporting and estimates. Enter actual salary, utilities and other payments once through **Finance → Expense Payments**.

### Sales, due collection and returns

1. Open **POS** for retail or **Sales → Wholesale Sales** for wholesale. Select the selling warehouse and available Finished Products. Select a registered customer for any due balance. Walk-in sales require full payment.
2. Enter the payment split if needed, review amount and cash change, then use the review/post action to finish the sale. Print the posted receipt or invoice. Check that sold stock decreased.
3. For an unpaid amount, use **Customer Receivables** or the sale's due-collection action. For returns, open **Sales Returns**, select the original sale, enter up to the remaining returnable quantity, review the due adjustment/refund and post. Recheck stock and Sales/Return Reconciliation.

### Supply planning and month-end

1. Under **Supply Planning**, create the branch/target-month **Demand Plan**, review suggestions or enter the planned and safety quantities, then approve.
2. From the approved plan, generate **Material Requirements**. Review the active recipes, available stock, incoming POs and Make/Buy recommendations. If needed, open a prefilled PO draft and verify its supplier, unit and quantity. Use **Low-Stock Alerts** to review current warehouse shortages.
3. In **Reporting → Monthly Controls**, open the period, prepare and review the company-wide Monthly Rate Sheet and lock it when approved. Review the monthly operational reports and stock valuation. After the period ends, close it and generate the Final Monthly Snapshot for the authorized company or branch scope.

## 5. First staging walkthrough

Use the [End-to-End Acceptance Test](SINETEC_POS_END_TO_END_TEST_PLAN.md) for the tested sample with exact inputs, expected balances and pass/fail checkpoints.

Use one branch, one warehouse, one supplier and one registered customer in staging. Start with a raw material, one made part and one finished product. Verify each result before moving on.

1. Post a purchase receipt for **10 units** of raw material. Confirm the item has **10 units** in the warehouse and verify the purchase journal with the accountant.
2. Complete a batch that consumes **4 raw units** and yields **4 good parts**. Expect **6 raw units** and **4 parts**. Assemble **2 finished units** with **2 parts**. Expect **2 parts** and **2 finished units**.
3. Post a sale for **2 finished units** with a partial payment for the registered customer. Verify zero finished stock and the outstanding balance. Collect the due and post a **1-unit return**. Verify **1 finished unit** comes back and reconcile the refund or due adjustment with the transaction history.
4. Compare the Stock Ledger, Supplier Due Summary, Customer Receivables, Trial Balance and monthly reports for the same branch. Check that an ordinary user from a separate branch sees only their approved menus and data.
5. Run one Demand Plan → MRP → PO draft flow, then test rate-sheet lock and month-end snapshot using a completed test month. Record any mismatch with its document reference before proceeding to the pilot.

Deployment and source-data transfer are covered in the [Developer Deployment Guide](SINETEC_POS_DEPLOYMENT_GUIDE.md).
