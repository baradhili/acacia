- [x] Need an option in bills to "add GST" per line item as well - for suppliers who show "ex-GST" for line items and then calculate it at subtotal

- [x] For bills that are "already paid" at entry time - still need a method to attach documents

- [x] allow for 4 digits after the decimal point as sometimes client reverse invoices carry many significant digits. 

- [ ] Need to be able to edit opening balances as admin or accountant.

- [ ] handle subscriptions that run for a time period overlapping financial years (ie. prorate for the year)

- [ ] Time entries need to have date and then amount of hours as default - optional they can have start and end time for the date as well as zero or more breaks 

- [ ] No way to edit or delete bill

- [ ] time entry - can only go to a project - project is linked to po

- [ ] Find where dates are in US m/d/y format and make them use the browser/system format

- [ ] data is not actually being written to most ifrs tables - they are being written to invoices, bills, payments etc - just not seeing them in teh IFRS_transactions table 

- [ ] 
- [ ] In teh invoices index view show an icon when invoices have a document attached. - possibly refelct this to all index views of items that can have attached documents.

- [ ] Update invoice format - if there is a PO - show amount remaining

- [ ] Have "+" button on "Create Bill" to be able to add supplier in 

- [ ] Country fields should be a dropdown - configurable "pinned countries" at top. use teh ISO country list.

- [ ] 

- [ ] Drop multiple files means all files should be uploaded

- [ ] ### 0. `createFromTimeEntries` doesn't actually create
  
  **Files:** `app/Http/Controllers/InvoiceController.php`, `routes/web.php`, missing views
  Investigation confirmed the bug is worse than reported: the POST route (`invoices.create-from-time-entries.store`) points to the **same render-only method** as the GET, so posting just re-renders and never persists. Additionally the view itself (`resources/views/invoices/create-from-time-entries.blade.php`) **does not exist**, so both GET and POST have been 500ing with `ViewNotFoundException` — the feature is unreachable/broken end-to-end, and nothing in the UI links to these routes (no tests either). The sibling `createFromPurchaseOrder` has the same problem (`invoices/create-from-po.blade.php` also missing; route `purchase-orders/{po}/create-invoice`). **Decision:** deferred per maintainer — time entries are out of scope for now. When revisiting: create the two missing views, split the POST into a real store handler that builds `InvoiceItem`s via `InvoiceItem::createFromTimeEntry()` (which already exists, unsave()-ed, for exactly this), and link `time_entry_id` on each item.
  
  - 🚫 *(deferred with #20 — time entries out of scope)* `time_entry_ids` validated in `store` but never linked to created items.
