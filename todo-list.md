- [x] Need an option in bills to "add GST" per line item as well - for suppliers who show "ex-GST" for line items and then calculate it at subtotal. Make it another checkbox

- [x] For bills that are "already paid" at entry time - still need a method to attach documents

- [x] allow for 4 digits after the decimal point as sometimes client reverse invoices carry many significant digits. 

- [x] Need to be able to edit opening balances as admin or accountant.

- [x] NOT YET - handle journaling subscriptions that run for a time period overlapping financial years (ie. prorate for the year) - have it as an flag  for a bill (Prepaid tick + service period on bill lines; prepayments:amortise posts per month-end across FYs)

- [x] Time entries need to have date and then amount of hours as default - optional they can have start and end time for the date as well as zero or more breaks 

- [x] No way to edit or delete bill - (Aug 2026) Unpaid bills (draft/open/overdue with $0 paid) are fully editable; any bill can be deleted from any status — payments are voided and their ledger shares reversed via BillLifecycleService (mirrored Dr Bank / Cr Expense / Cr GST entries); paid bills are corrected by unapplying the payment first (bills/{bill}/payments/{payment}/unapply) which reverts the bill to editable; supplier payments got a Void action and posted payments can no longer be hard-deleted (which used to orphan their journal entries).

- [x] How to handle "end/start of financial year" - (Aug 2026) Staged year-end close: trial close (checklist + proposed closing entries, `fiscal-year:trial` / Financial Years page) → approval (accountant/admin ≠ requester) → execute (`fiscal-year:close`) posts two JEs (reference FY-CLOSE-{year}) transferring every P&L balance to Retained Earnings (3200), marks the IFRS ReportingPeriod CLOSED, locks the year's app periods and ensures next-FY exists OPEN. Reopen (`fiscal-year:reopen` / UI) mirrors the entries back out. Closed FYs block payment/bill-payment dates, voids and unapplies with friendly errors (NotInClosedPeriod rule); reports exclude FY-CLOSE references from P&L movement so historical statements survive the close, and the balance sheet stops adding on-the-fly profit once the FY is closed (no double count). BAS/company-tax FY boundaries derive from entity.year_start. CLI `--force` bypasses the approval workflow/checklist; the dashboard and Financial Years page warn while a prior FY is unclosed.

- [x] time entry - can only go to a project - (Aug 2026) entries can now target a client directly (ad-hoc client work) or stand alone as internal time; `client_id` denormalised onto `time_entries` (forced from the project when one is set, backfilled historically), Client select added to the entry forms, reports/unbilled-time views resolve the client in all three cases. 

- [x] data is not actually being written to most ifrs tables - they are being written to the tables - invoices, bills, payments etc - just not seeing them in the IFRS_transactions table - plan in .zcode/plans

- [x] Build a report for Aust BAS

- [x] Build a report for Aust Company tax

- [x] In teh invoices index view show an icon when invoices have a document attached. - possibly refelct this to all index views of items that can have attached documents.

- [x] Update invoice format - if there is a client PO linked - show amount remaining after this invoice

- [x] Have "+" button on "Create Bill" to be able to add supplier in 

- [x] Country fields should be a dropdown - configurable "pinned countries" at top. use teh ISO country list.

- [x] Drop multiple files means all files should be uploaded

- [x] Need a way to identify capital purchases category in bills/chart of accounts (and update bas report)

- [x] review [Sales Cycle - Eloquent IFRS](https://ekmungai.github.io/ifrs-docs/v5docs/sales-cycle/) , [Purchase Cycle - Eloquent IFRS](https://ekmungai.github.io/ifrs-docs/v5docs/purchase-cycle/) , and [Compound Journal Entries - Eloquent IFRS](https://ekmungai.github.io/ifrs-docs/v5docs/compound-journals/) and check for any deviations done in teh app. update this document with teh proposed plan.

- [x] Process of adding profile picture fails

- [x] No way of creating an opening balance for franking account

- [x] opening balances view doesn't add totals - should totals even be visible?

- [x] Need to be able to set open balances for earlier fiscal years than this one

- [ ] year end close - if there is not another admin/accountant - then pass the approval request to the user that raised it

- [ ] shareholders - share held at what value? $10 for 1000

- [x] Company details - add svg or png logo - failed as it errors with "Name field required" on logo upload validation. There is an existing name

- [ ] Need to handle clients who want timesheet reports for project by week sum

- [ ] need to handle client who "reverse invoice" as in I fill their timesheet system and they send me a payment that is itemised like my time-based invoice timesheet

- [ ] display the uploaded logo if it exists when viewing the bill record

- [ ] use company logo that is uploaded on pdf invoice

- [ ] allow abn/acn/tfn to be display formatted in the way they normally are - also allow entry with the usual spaces

- [x] update tax report: review .zcode/ato-company-tax/company-tax.md. create plan including branch. Only expect a single manual opening balance entry for a company (migration into this system). However need an admin process to "close" a financial year  (which should potentially move some ledger balances  and create opening balances for the subsequent financial year)  - so if I have entered an opening balance debit for current assets and a matching credit to equity then the correct output should show - (Sep 2026, branch feat/fy-close-opening-balances) Done. Opening sets are now superseding snapshots: the year-end close (the existing admin trial → approve → execute process) writes FY {year+1}'s opening balances from the closing position (one Balance row per balance-sheet account incl. the profit closed into Retained Earnings, dated the year end, reference FY-CLOSE-{year}-OB), so opening balances are entered by hand exactly once, at migration. Reports (trial balance, balance sheet, account statement, company tax item 8, trial close) read as-at positions via OpeningBalances::balanceAt() — the latest snapshot plus only post-snapshot ledger movement — so a migration set (debit current assets / credit equity) shows in item 8 D/E and the new supplementary equity reconciliation, and multiple sets can never double-count. The Opening Balances screen renders close-generated sets read-only (reopen the year to change); reopen() removes the generated set.

- [ ] Admin should have a backup button that backs up db and stored items with the usual backup things like backup frequency, number of backups kept.

- [ ] setting to prune transactions in closed years after x years (default 7 years)

- [ ] allow bill and invoice adjustment items that might be negative. allow adjustments to subtotal and gst separately.

- [x] company details - index wants resignation date - should be optional

- [x] Company details page missing company name and optional trading name

- [x] 2026_08_28_000001_create_shares_and_dividends_tables .......... 43.80ms FAIL
  
    Illuminate\Database\QueryException 
  
   SQLSTATE[23000]: Integrity constraint violation: 1451 Cannot delete or update a parent row: a foreign key constraint fails (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: erp, SQL: drop table if exists `dividend_declarations`)

- [x] ### 0. `createFromTimeEntries` doesn't actually create
  
  **Files:** `app/Http/Controllers/InvoiceController.php`, `routes/web.php`, missing views
  (Sep 2026) Done. Invoiced state now derives from `invoice_items.time_entry_id` via `TimeEntry::invoiceItem()` (the phantom `invoiced` column writes are gone; cancelled invoices release their entries). `/invoices/create` turns checked unbilled entries into real linked invoice lines. Dedicated screens: "New from Time Entries" (GET picker with client filter + real `storeFromTimeEntries` POST) and PO "Create Invoice" (`storeFromPurchaseOrder`) — both build items via `InvoiceItem::createFromTimeEntry()`, screen for approved/billable/uninvoiced/single-client before persisting, and the PO flow drives `used_amount` through the existing observer chain. The previously missing views (`create-from-time-entries`, `create-from-po`, `credit-notes/create-from-invoice`) all exist, plus drive-by fixes to the dashboard unbilled-time widget (phantom columns) and the `australian.invoice.terms` config key. Covered by `tests/Feature/CreateInvoiceFromTimeEntriesTest.php`.
