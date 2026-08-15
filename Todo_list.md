# Invoice & Payment Subsystem — Bug List

Status legend: ☐ open · ✅ done

### 0. 🚫 Won't fix (deferred) — `createFromTimeEntries` doesn't actually create

**Files:** `app/Http/Controllers/InvoiceController.php`, `routes/web.php`, missing views
Investigation confirmed the bug is worse than reported: the POST route (`invoices.create-from-time-entries.store`) points to the **same render-only method** as the GET, so posting just re-renders and never persists. Additionally the view itself (`resources/views/invoices/create-from-time-entries.blade.php`) **does not exist**, so both GET and POST have been 500ing with `ViewNotFoundException` — the feature is unreachable/broken end-to-end, and nothing in the UI links to these routes (no tests either). The sibling `createFromPurchaseOrder` has the same problem (`invoices/create-from-po.blade.php` also missing; route `purchase-orders/{po}/create-invoice`).
**Decision:** deferred per maintainer — time entries are out of scope for now. When revisiting: create the two missing views, split the POST into a real store handler that builds `InvoiceItem`s via `InvoiceItem::createFromTimeEntry()` (which already exists, unsave()-ed, for exactly this), and link `time_entry_id` on each item.

- 🚫 *(deferred with #20 — time entries out of scope)* `time_entry_ids` validated in `store` but never linked to created items.

## 1 ☐ Replace existing "Expenses" UI and linked files.

Existing Expenses is confusing. change to "Bills" and make it similar to "Invoices/Payments" - except Bills are invoices _from_ a supplier. There should also be an option to record Bills that were paid immediately - such as things like parking, entertainment, online purchases etc. Make sure Expense categories align to Journals

### 2 ☐ Change the invoice payment allocation and bill payment allocation methods.

We should default to 100% allocation to the nominated invoice or bill respectively. It

### 3 ☐ Replace "php artisan test" with a command that runs unit tests and uses behat to run feature tests


---

## Commits on `Accounts-payable-fixes`

| Commit    | Item     | Summary                                                                                           |
| --------- | -------- | ------------------------------------------------------------------------------------------------- |
|  |       |    |
