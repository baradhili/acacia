- [x] Need an option in bills to "add GST" per line item as well - for suppliers who show "ex-GST" for line items and then calculate it at subtotal. Make it another checkbox

- [x] For bills that are "already paid" at entry time - still need a method to attach documents

- [x] allow for 4 digits after the decimal point as sometimes client reverse invoices carry many significant digits. 

- [x] Need to be able to edit opening balances as admin or accountant.

- [x] NOT YET - handle journaling subscriptions that run for a time period overlapping financial years (ie. prorate for the year) - have it as an flag  for a bill (Prepaid tick + service period on bill lines; prepayments:amortise posts per month-end across FYs)

- [x] Time entries need to have date and then amount of hours as default - optional they can have start and end time for the date as well as zero or more breaks 

- [x] No way to edit or delete bill - (Aug 2026) Unpaid bills (draft/open/overdue with $0 paid) are fully editable; any bill can be deleted from any status — payments are voided and their ledger shares reversed via BillLifecycleService (mirrored Dr Bank / Cr Expense / Cr GST entries); paid bills are corrected by unapplying the payment first (bills/{bill}/payments/{payment}/unapply) which reverts the bill to editable; supplier payments got a Void action and posted payments can no longer be hard-deleted (which used to orphan their journal entries).

- [ ] How to handle "end/start of financial year"

- [ ] THINK THROUGH - time entry - can only go to a project - project is linked to po

- [ ] Find where dates are in US m/d/y format and make them use the browser/system format

- [x] data is not actually being written to most ifrs tables - they are being written to the tables - invoices, bills, payments etc - just not seeing them in the IFRS_transactions table - plan in .zcode/plans

- [x] Build a report for Aust BAS

- [x] Build a report for Aust Company tax

- [x] In teh invoices index view show an icon when invoices have a document attached. - possibly refelct this to all index views of items that can have attached documents.

- [ ] Update invoice format - if there is a PO - show amount remaining

- [ ] Have "+" button on "Create Bill" to be able to add supplier in 

- [x] Country fields should be a dropdown - configurable "pinned countries" at top. use teh ISO country list.

- [x] Drop multiple files means all files should be uploaded

- [x] Need a way to identify capital purchases category in bills/chart of accounts (and update bas report)

- [ ] review [Sales Cycle - Eloquent IFRS](https://ekmungai.github.io/ifrs-docs/v5docs/sales-cycle/) , [Purchase Cycle - Eloquent IFRS](https://ekmungai.github.io/ifrs-docs/v5docs/purchase-cycle/) , and [Compound Journal Entries - Eloquent IFRS](https://ekmungai.github.io/ifrs-docs/v5docs/compound-journals/) and check for any deviations done in teh app. update this document with teh proposed plan.

- [ ] ### 0. `createFromTimeEntries` doesn't actually create
  
  **Files:** `app/Http/Controllers/InvoiceController.php`, `routes/web.php`, missing views
  Investigation confirmed the bug is worse than reported: the POST route (`invoices.create-from-time-entries.store`) points to the **same render-only method** as the GET, so posting just re-renders and never persists. Additionally the view itself (`resources/views/invoices/create-from-time-entries.blade.php`) **does not exist**, so both GET and POST have been 500ing with `ViewNotFoundException` — the feature is unreachable/broken end-to-end, and nothing in the UI links to these routes (no tests either). The sibling `createFromPurchaseOrder` has the same problem (`invoices/create-from-po.blade.php` also missing; route `purchase-orders/{po}/create-invoice`). **Decision:** deferred per maintainer — time entries are out of scope for now. When revisiting: create the two missing views, split the POST into a real store handler that builds `InvoiceItem`s via `InvoiceItem::createFromTimeEntry()` (which already exists, unsave()-ed, for exactly this), and link `time_entry_id` on each item.
  
  - 🚫 *(deferred with #20 — time entries out of scope)* `time_entry_ids` validated in `store` but never linked to created items.
