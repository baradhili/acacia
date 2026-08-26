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

Label references follow the published Company tax return 2026 (NAT 0656) instructions (published 30 May 2026). Section 5 maps source data to the form's item numbers and label letters (e.g. Item 6 label C = "Other sales of goods and services"). The 2026 form has no label changes from 2025.

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

Rather than scanning raw journal lines, cash movements are identified by joining
ledger rows to their parent transaction: this system posts every cash movement as
an Eloquent IFRS `JournalEntry` whose **main account is a BANK account** — client
receipts post Dr Bank / Cr Revenue (+ Cr GST), supplier payments post Cr Bank /
Dr Expense (+ Dr GST). The report therefore restricts Item 6 amounts to ledger
rows whose parent transaction's main account is a BANK account, which excludes
non-cash journals (depreciation, revaluations, forex) by construction. Because the
GST back-out legs post to the same revenue/expense account, a per-account ledger
balance is already GST-exclusive.

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

The mapping below follows the real Company tax return 2026 label structure. Amounts
are sourced from bank-settled (cash) ledger postings and are GST-exclusive, per the
form's instruction to exclude input tax credit entitlements.

### 5.1 Income fields (Item 6 — income)

| ATO label | Eloquent IFRS source logic |
|---|---|
| 6-A Gross payments where ABN not quoted | No source (no withholding on receipts); reported as $0. |
| 6-B Gross payments subject to foreign resident withholding | Not applicable — resident company; $0. |
| 6-C Other sales of goods and services | Net cash receipts posted to operating revenue accounts (4100, 4110, 4120, 4130), GST-exclusive. Primary label for a services company; fallback for unmapped operating revenue. |
| 6-D Gross distribution from partnerships | Not applicable; $0. |
| 6-E Gross distribution from trusts | Not applicable; $0. |
| 6-F Gross interest | Net cash receipts to interest income accounts (4510), GST-exclusive. |
| 6-G Gross rent and other leasing and hiring income | Not applicable (professional services); $0. |
| 6-H Total dividends | Out of scope — dividend/franking module not implemented; $0. |
| 6-I Fringe benefit employee contributions | Not applicable; $0. |
| 6-Q Assessable government industry payments | Not applicable; $0. |
| 6-J Unrealised gains on revaluation of assets to fair value | Non-cash; excluded. |
| 6-R Other gross income | Net cash receipts to other income accounts (4520) and fallback for other unmapped non-operating revenue, GST-exclusive. |
| 6-S Total income | Computed: sum of 6-A to 6-R. |

### 5.2 Expense fields (Item 6 — expenses)

Per the ATO instructions, expense amounts are taken from the financial statements
(cash-basis ledger here), exclude input tax credits, and non-deductible amounts are
added back at Item 7 label W.

| ATO label | Eloquent IFRS source logic |
|---|---|
| 6-B Foreign resident withholding expenses | Not applicable — resident company; $0. |
| 6-A Cost of sales | No trading stock / COGS accounts seeded (services entity); $0. |
| 6-C Contractor, sub-contractor and commission expenses | Account 5110 Contract Labour. |
| 6-D Superannuation expenses | Not sourced — no payroll/superannuation ledger; $0 with note. |
| 6-E Bad debts | Account 8100 Bad Debts; typically nil on cash basis. |
| 6-F Lease expenses within Australia | Not seeded separately (premises rent is reported at 6-H). |
| 6-I Lease expenses overseas | Not applicable; $0. |
| 6-H Rent expenses | Account 7100 Rent & Lease (tenant rent of land and buildings). |
| 6-V Interest expenses within Australia | Account 8200 Interest Expense. |
| 6-J Interest expenses overseas | Not applicable; $0. |
| 6-U Royalty expenses overseas | Not applicable; $0. |
| 6-W Royalty expenses within Australia | Not seeded; falls back to 6-S if posted. |
| 6-X Depreciation expenses | Non-cash book depreciation (account 7900) is excluded from this cash-basis report; SBE capital deductions are claimed via Item 10 instead of 7-F. |
| 6-Y Motor vehicle expenses | Account 5400 Motor Vehicle Expenses; running expenses only — private-use reduction is a manual judgement. |
| 6-Z Repairs and maintenance | Not seeded; falls back to 6-S if posted. |
| 6-G Unrealised losses on revaluation of assets to fair value | Non-cash; excluded. |
| 6-S All other expenses | Accounts 5100, 5120, 5200, 5300, 7250, 7300, 7400, 7500, 7600, 7700, 7800, 8300, 8900 plus fallback for any unmapped expense accounts. Includes salaries & wages, accounting/legal fees, advertising, bank charges and insurance — these have no separate labels on the 2026 form. |
| 6-Q Total expenses | Computed: sum of 6-B to 6-S. |
| 6-T Total profit or loss | 6-S less 6-Q. |

### 5.3 Reconciliation fields (Item 7 — reconciliation to taxable income or loss)

| ATO label | Eloquent IFRS source logic |
|---|---|
| 7-W Non-deductible expenses (add-back) | Cash payments posted to accounts flagged non-deductible: 5500 Meals & Entertainment (entertainment), 8400 Income Tax Expense, 8410 Franking Deficit Tax Expense. |
| 7-V Exempt income (subtraction) | Accounts flagged exempt in the mapping (none seeded by default). |
| 7-Q Other income not included in assessable income (subtraction) | Accounts flagged non-assessable in the mapping (none seeded by default). |
| 7-F Deduction for decline in value of depreciating assets | Left blank for small business entities using simplified depreciation — claim via Item 10 instead. |
| 7-R Tax losses deducted | Not tracked by the system; $0 with note. |
| Other Item 7 labels (CGT, TOFA, R&D, franking credit gross-ups, etc.) | $0 / not applicable for this entity. |
| 7-T Taxable or net income or loss | 6-T plus add-backs, less subtractions. |

### 5.4 Financial and other information (Item 8) and SBE depreciation (Item 10)

| ATO label | Eloquent IFRS source logic |
|---|---|
| 8-C Trade debtors | As-at ledger balance of RECEIVABLE accounts at 30 June (incl. opening balances). |
| 8-D All current assets | As-at balances of BANK, RECEIVABLE, CURRENT_ASSET and INVENTORY accounts. |
| 8-E Total assets | As-at balances of all asset account types. |
| 8-F Trade creditors | As-at balance of PAYABLE accounts. |
| 8-G All current liabilities | As-at balances of PAYABLE and CURRENT_LIABILITY accounts. |
| 8-H Total liabilities | As-at balances of all liability account types. |
| 8-D Total salary and wage expenses (information label) | Accounts 5100 Salaries & Wages + 5120 Director Remuneration (gross cash paid). |
| 8-J Franked dividends paid / 8-K Unfranked dividends paid | Account 3400 Dividends Paid if posted; expected $0 until the dividend module exists. |
| 8-P Opening franking account balance / 8-M Closing franking account balance | Not tracked — franking account module not implemented; rendered with a note. |
| 10-A / 10-B SBE simplified depreciation | Capital purchases = net cash paid to NON_CURRENT_ASSET accounts (110–160) during the year, as reference data for the instant asset write-off (10-A) and general small business pool (10-B) labels; the deductible split is a manual judgement. |
| Calculation statement | Estimated tax = 7-T × company tax rate from configuration (default 25% base rate entity; 30% otherwise). |

---

## 6. Report Output Layout

### 6.1 CSV export

| Column | Description |
|---|---|
| `entity_abn` | Australian Business Number |
| `entity_tfn` | Tax File Number |
| `income_year` | 2026 |
| `ato_item` | Form item number, e.g. `6` |
| `ato_label` | Label letter within the item, e.g. `C` |
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
| V11 | Every amount must come from a bank-settled transaction (parent transaction's main account is a BANK account) or an approved adjustment journal. |
| V12 | All cash payments included in expense labels must be posted to expense accounts (`OPERATING_EXPENSE`, etc.). |
| V13 | VAT (`Vat`) amounts must be correctly excluded from income and expense totals based on the entity’s GST registration status. |

---
