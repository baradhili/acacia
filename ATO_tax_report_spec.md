# Report Specification: ATO Company Tax Return 2026 – Small Business Cash-Basis Bridging Report (Eloquent IFRS Edition)

| | |
|---|---:|
| Document version | 2.0 |
| Date prepared | 2026-08-16 |
| Income year | 1 July 2025 – 30 June 2026 |
| Reporting currency | Australian dollars (AUD) |
| Source system | Eloquent IFRS – Laravel double-entry accounting subsystem |
| Tax return | ATO Company Tax Return 2026 |
| Accounting basis for tax | Cash basis – small business entity |
| Language | en_GB |

---

## 1. Purpose

This report converts IFRS accrual journal data from the Eloquent IFRS accounting subsystem into cash-basis amounts required for the Australian Taxation Office (ATO) Company Tax Return 2026 for a small business entity. The report must:

- identify cash and cash-equivalent transactions from the Eloquent IFRS ledger;
- exclude non-cash IFRS accrual entries;
- apply GST and small business cash-basis rules;
- map cash receipts and payments to the relevant ATO return labels;
- produce an auditable trail from each ATO label back to the source Eloquent IFRS transactions and line items.

Label references in this specification are indicative and must be verified against the final published ATO Company Tax Return 2026.

---

## 2. Scope

### 2.1 In scope

- Australian resident small business company using cash-basis accounting for income tax.
- Income year ended 30 June 2026.
- Eloquent IFRS transaction records with transaction dates from 1 July 2025 to 30 June 2026.
- Cash receipts, cash payments, bank transfers, EFTPOS, credit card transactions and petty cash.
- All transaction types supported by Eloquent IFRS including `CashSale`, `CashPurchase`, `ClientInvoice`, `SupplierBill`, `ClientReceipt`, `SupplierPayment` and related assignments.

### 2.2 Out of scope

- Consolidated or multiple-entry consolidated (MEC) group taxation.
- Non-small-business entities.
- Accrual-basis tax reporting.
- GST return preparation, although GST amounts must be excluded from the ATO income and expense labels.

---

## 3. Source Data

### 3.1 Eloquent IFRS source models

The Eloquent IFRS package provides a fully featured double-entry accounting subsystem with the following key models:

| Model | Description |
|---|---|
| `IFRS\Models\Entity` | Reporting entity (company) with reporting currency |
| `IFRS\Models\Account` | Chart of accounts with account types (BANK, RECEIVABLE, PAYABLE, OPERATING_REVENUE, OPERATING_EXPENSE, NON_CURRENT_ASSET, CONTROL, etc.) |
| `IFRS\Models\Transaction` | Base transaction model (polymorphic) |
| `IFRS\Transactions\CashSale` | Cash sale transaction |
| `IFRS\Transactions\CashPurchase` | Cash purchase transaction |
| `IFRS\Transactions\ClientInvoice` | Credit sale (invoice) transaction |
| `IFRS\Transactions\SupplierBill` | Credit purchase (bill) transaction |
| `IFRS\Transactions\ClientReceipt` | Receipt from client |
| `IFRS\Transactions\SupplierPayment` | Payment to supplier |
| `IFRS\Models\LineItem` | Line items within transactions (quantity, amount, account mapping) |
| `IFRS\Models\Vat` | VAT/GST rates and codes (output and input VAT) |
| `IFRS\Models\Assignment` | Assignment of receipts/payments to invoices/bills |
| `IFRS\Models\Currency` | Currencies and exchange rates |
| `IFRS\Models\ReportingPeriod` | Reporting periods (period count, calendar year) |

### 3.2 Key fields in Eloquent IFRS transactions

| Field | Description |
|---|---|
| `id` | Unique transaction identifier |
| `account_id` | Primary account (cash account for cash transactions) |
| `date` | Transaction date (economic event date) |
| `narration` | Transaction description |
| `transaction_type` | Polymorphic type (CashSale, CashPurchase, ClientInvoice, etc.) |
| `lineItems` | Collection of line items (debit/credit entries) |
| `vat` | VAT/GST applied to line items |
| `assignments` | Assignment records for cleared transactions |

### 3.3 Key fields in `LineItem`

| Field | Description |
|---|---|
| `id` | Unique line item identifier |
| `transaction_id` | Parent transaction identifier |
| `account_id` | Account to which this line item is posted |
| `narration` | Line item description |
| `quantity` | Quantity |
| `amount` | Amount in AUD |
| `vat_id` | VAT/GST rate applied |

---

## 4. Cash-Basis Conversion Rules

### 4.1 Cash-equivalent accounts

Eloquent IFRS provides the following account types that must be treated as cash-equivalent for the cash-basis report:

| Account type (`Account::`) | Cash-basis treatment |
|---|---|
| `BANK` | Cash receipt when debited; cash payment when credited |
| `CASH` (if configured) | Cash receipt when debited; cash payment when credited |

### 4.2 Transaction-based cash flow identification

Rather than scanning raw journal lines, Eloquent IFRS transactions provide a structured approach to identifying cash movements:

| Transaction type | Cash flow direction | ATO treatment |
|---|---|---|
| `CashSale` | Cash inflow (Bank account debited) | Assessable income when received |
| `ClientReceipt` | Cash inflow (Bank account debited) | Assessable income when received |
| `CashPurchase` | Cash outflow (Bank account credited) | Deductible expense when paid |
| `SupplierPayment` | Cash outflow (Bank account credited) | Deductible expense when paid |

### 4.3 Income recognition

Under cash-basis accounting, income is recognised only when cash or cash-equivalent is actually received.

- Select `CashSale` and `ClientReceipt` transactions posted within the income year.
- For `ClientReceipt` transactions, map the associated `Assignment` records to identify which invoices are being cleared.
- Map the corresponding revenue account (`OPERATING_REVENUE` or `NON_OPERATING_REVENUE`) to an income label.
- Exclude GST collected from the cash receipt using the `Vat` model.
- Exclude non-assessable receipts such as:
  - loan proceeds;
  - capital contributions;
  - tax refunds;
  - GST collected.

### 4.4 Expense recognition

Under cash-basis accounting, an expense is deductible only when cash or cash-equivalent is actually paid.

- Select `CashPurchase` and `SupplierPayment` transactions posted within the income year.
- Map the corresponding expense account (`OPERATING_EXPENSE`, `OVERHEAD_EXPENSE`, `DIRECT_EXPENSE` or `OTHER_EXPENSE`) to an expense label.
- Exclude GST paid from the cash payment using the `Vat` model.
- Exclude non-deductible amounts such as:
  - private or domestic portion;
  - entertainment;
  - fines and penalties;
  - political donations;
  - capital asset purchases that are not immediately deductible under small business simplified depreciation.

### 4.5 Non-cash IFRS transactions to exclude

The following Eloquent IFRS transaction types and models must be excluded from the cash-basis report:

| Transaction / Model | Reason for exclusion |
|---|---|
| `ClientInvoice` | Credit sale – no cash movement |
| `SupplierBill` | Credit purchase – no cash movement |
| Depreciation entries | Non-cash (managed via account balances, not transactions) |
| Impairment adjustments | Non-cash |
| Fair value adjustments | Non-cash |
| Forex difference transactions | Non-cash – automated posting of forex differences |
| Opening balances | Non-cash – start of year balances |

### 4.6 GST treatment

Eloquent IFRS supports VAT/GST at the line item level via the `Vat` model:

- If the entity is registered for GST and uses the cash basis for GST, report all ATO income and expense labels net of recoverable GST.
- GST collected on cash receipts (output VAT) must be excluded from income.
- GST paid on cash payments (input VAT) must be excluded from expenses if an input tax credit is available.
- If the entity is not registered for GST, GST-inclusive amounts may need to be included in income and expenses. This must be configured in the tax mapping table.

### 4.7 Small business cash-basis adjustments

| Item | Treatment |
|---|---|
| Prepayments | If the prepayment is for a period of 12 months or less and ends by the next income year, it may be deductible when paid. Otherwise apportion. |
| Trading stock | A small business entity may choose not to account for changes in trading stock if the difference between opening and closing stock is less than $5,000. |
| Capital assets | Non-cash depreciation is excluded. Capital asset purchases are tested against the current simplified depreciation or instant asset write-off rules for the 2025-26 income year. |
| Bad debts | Usually no cash-basis deduction. If a bad debt is later recovered, the recovery is cash income (via `ClientReceipt`). |
| Employee salaries | Include gross salary amounts paid. This includes net wages paid to employees and PAYG withholding remitted to the ATO. |
| Superannuation | Deductible only when actually paid to a complying superannuation fund. |

---

## 5. ATO Field Mapping

### 5.1 Income fields

| ATO field | Eloquent IFRS source logic |
|---|---|
| Total income | Sum of all `CashSale` and `ClientReceipt` transactions mapped to assessable income accounts (`OPERATING_REVENUE`, `NON_OPERATING_REVENUE`), net of GST (`Vat`), excluding non-assessable receipts. |
| Gross payments where ABN not quoted | Cash payments made to suppliers without a quoted ABN, if required to be reported on the return. |
| Gross payments subject to foreign resident withholding | Cash payments subject to foreign resident withholding, if required to be reported. |
| Other assessable government industry payments | Cash receipts from government industry payments, if applicable. |

### 5.2 Expense fields

| ATO field | Eloquent IFRS source logic |
|---|---|
| Total expenses | Sum of all `CashPurchase` and `SupplierPayment` transactions mapped to deductible expense accounts (`OPERATING_EXPENSE`, `OVERHEAD_EXPENSE`, `DIRECT_EXPENSE`, `OTHER_EXPENSE`), net of GST (`Vat`). |
| Accounting fees | Cash payments for accounting, audit and tax services. |
| Advertising | Cash payments for advertising and marketing. |
| Bad debts | Only cash recoveries or actual cash losses directly related to previously included income. |
| Bank charges | Cash payments for bank fees and charges. |
| Cost of sales | Cash payments for goods sold, net of trading stock adjustments if applicable. |
| Depreciation expenses | Non-cash depreciation is excluded. Capital asset purchases are considered separately under small business simplified depreciation. |
| Employee superannuation | Cash payments to complying superannuation funds for employees. |
| Fringe benefits tax | Cash payments of fringe benefits tax. |
| Insurance | Cash payments for insurance premiums. |
| Interest expenses within Australia | Cash interest paid to Australian lenders. |
| Interest expenses overseas | Cash interest paid to foreign lenders. |
| Legal fees | Cash payments for legal services. |
| Motor vehicle expenses | Cash payments for fuel, repairs, registration, insurance and other motor vehicle costs, reduced for private use. |
| Repairs and maintenance | Cash payments for repairs and maintenance. |
| Rent expenses | Cash payments for rent and leasing of business premises or equipment. |
| Salaries and wages | Gross cash salary and wage payments, including net wages paid and PAYG withholding remitted. |
| Subcontractor payments | Cash payments to subcontractors for business services. |
| Other expenses | All other deductible cash expenses not separately listed. |

### 5.3 Reconciliation fields

| ATO field | Eloquent IFRS source logic |
|---|---|
| Net profit or loss | Total income less total expenses from the cash-basis report. |
| Non-deductible expenses | Cash payments that are not deductible, such as private portion, entertainment, fines and penalties. |
| Income not assessable | Cash receipts that are not assessable, such as capital contributions and exempt income. |
| Deductible expenses not in accounts | Cash payments deductible for tax but not already included in expense labels, such as eligible capital asset purchases under simplified depreciation. |
| Taxable income | Net profit or loss plus non-deductible expenses, less non-assessable income, plus deductible expenses not in accounts. |

---

## 6. Report Output Layout

### 6.1 CSV export

| Column | Description |
|---|---|
| `entity_abn` | Australian Business Number |
| `entity_tfn` | Tax File Number |
| `income_year` | 2026 |
| `ato_field_code` | Internal field code, e.g. `INC_01`, `EXP_12` |
| `ato_field_name` | ATO label description |
| `amount_aud` | Amount in AUD, rounded to whole dollars |
| `source_transaction_ids` | Eloquent IFRS transaction IDs included in the amount |
| `source_line_item_ids` | Eloquent IFRS line item IDs included in the amount |
| `adjustment_reference` | Reference to manual adjustment journal, if any |
| `validation_status` | `PASS` or `FAIL` |

### 6.2 PDF summary

The PDF summary must include:

- entity name, ABN and TFN;
- income year 1 July 2025 – 30 June 2026;
- total income;
- total expenses;
- net profit or loss;
- taxable income or loss;
- reconciliation totals for non-deductible and non-assessable items;
- preparation date and version.

---

## 7. Validation and Reconciliation

### 7.1 Data validation rules

| Rule | Description |
|---|---|
| V01 | Total income label must equal sum of all income field amounts. |
| V02 | Total expenses label must equal sum of all expense field amounts. |
| V03 | Net profit or loss must equal total income minus total expenses. |
| V04 | Taxable income must equal net profit or loss plus non-deductible expenses, less non-assessable income, plus deductible expenses not in accounts. |
| V05 | Total cash receipts from `CashSale` and `ClientReceipt` transactions must reconcile to total income plus GST collected plus non-assessable cash receipts. |
| V06 | Total cash payments from `CashPurchase` and `SupplierPayment` transactions must reconcile to total expenses plus GST paid plus non-deductible cash payments plus capital purchases. |
| V07 | No non-cash transaction type (`ClientInvoice`, `SupplierBill`, depreciation, forex adjustments) may be included unless it represents an actual cash or credit card transaction. |
| V08 | All amounts must be rounded to the nearest whole dollar. |
| V09 | Negative amounts are not permitted except for the net loss field. |
| V10 | Every amount must trace back to at least one Eloquent IFRS transaction record or an approved adjustment journal. |

### 7.2 Reconciliation to bank statements

The report must be reconciled to the entity’s bank and credit card statements for the period 1 July 2025 to 30 June 2026. Any differences must be explained and recorded in the adjustment journal.

### 7.3 Eloquent IFRS-specific validations

| Rule | Description |
|---|---|
| V11 | All `CashSale` and `ClientReceipt` transactions must have a valid `Assignment` or be identifiable as cash receipts via account type. |
| V12 | All `CashPurchase` and `SupplierPayment` transactions must be posted to expense accounts (`OPERATING_EXPENSE`, etc.) for inclusion. |
| V13 | VAT (`Vat`) amounts must be correctly excluded from income and expense totals based on the entity’s GST registration status. |

---
