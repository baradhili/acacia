# Invoice & Payment Subsystem — Bug List

Status legend: ☐ open · ✅ done

### 0. 🚫 Won't fix (deferred) — `createFromTimeEntries` doesn't actually create

**Files:** `app/Http/Controllers/InvoiceController.php`, `routes/web.php`, missing views
Investigation confirmed the bug is worse than reported: the POST route (`invoices.create-from-time-entries.store`) points to the **same render-only method** as the GET, so posting just re-renders and never persists. Additionally the view itself (`resources/views/invoices/create-from-time-entries.blade.php`) **does not exist**, so both GET and POST have been 500ing with `ViewNotFoundException` — the feature is unreachable/broken end-to-end, and nothing in the UI links to these routes (no tests either). The sibling `createFromPurchaseOrder` has the same problem (`invoices/create-from-po.blade.php` also missing; route `purchase-orders/{po}/create-invoice`).
**Decision:** deferred per maintainer — time entries are out of scope for now. When revisiting: create the two missing views, split the POST into a real store handler that builds `InvoiceItem`s via `InvoiceItem::createFromTimeEntry()` (which already exists, unsave()-ed, for exactly this), and link `time_entry_id` on each item.

- 🚫 *(deferred with #20 — time entries out of scope)* `time_entry_ids` validated in `store` but never linked to created items.

## 1 ✅ Replace existing "Expenses" UI and linked files.

Existing Expenses is confusing. change to "Bills" and make it similar to "Invoices/Payments" - except Bills are invoices _from_ a supplier. There should also be an option to record Bills that were paid immediately - such as things like parking, entertainment, online purchases etc. Make sure Expense categories align to Journals

**Done:** Bills/BillItems/BillPayments/BillPaymentAllocations mirror the AR subsystem (state machine, `createWithUniqueNumber` race retry, item upsert on edit, edit guards, allocation/over-allocation logic). Categories are now per-line IFRS expense accounts picked from the chart of accounts, so journals align by construction. Per-line GST treatment (10% inclusive vs GST-free). Paid-at-entry (parking/entertainment/online) creates bill+payment+allocation+ledger posting in one transaction; the Wise debit auto-create path reuses it. Receipts replaced by the Document morph (like invoices). `BillPayment::postToIFRS()` posts Cr Bank / Dr expense (net) / Dr GST per line — and actually calls `post()` so ledger rows are written. Legacy expenses migrated (paid ones carry their old journal id so nothing double-posts) and the expenses tables dropped; bank transactions / reconciliation history / documents repointed. Downstream consumers (dashboard, widgets, GST/tax reports, reconciliation, audit) re-pointed; dashboard daily-cashflow `paid_at` bug and reconciliation `Client`-as-supplier bug fixed en route.

### 2 ☐ Change the invoice payment allocation and bill payment allocation methods.

We should default to 100% allocation to the nominated invoice or bill respectively. It

### 3 ☐ Replace "php artisan test" with a command that runs unit tests and uses behat to run feature tests

### 4 ✅ AR: `Invoice::updateStatusFromPayments()` could never un-pay an invoice (pre-existing, failed at HEAD)

**Files:** `app/Models/Invoice.php`, `tests/Feature/PaymentTest.php`
Found while verifying the Bills work (failed on a clean checkout of this branch, unrelated to Bills). Three defects fixed in `updateStatusFromPayments()`:
- The no-payments branch excluded `STATUS_PAID`, so removing/voiding allocations left paid invoices `paid` forever (`reallocating_payment_updates_invoice_status` expected `overdue`, got `paid`). Now reverts: paid → overdue if past due, else sent; `paid_at` cleared. Only draft/cancelled are never clobbered.
- The status decision used the possibly-stale in-memory `total` — when items were added via the item saved-hook on a different instance, `total` was still 0 and the `$total > 0` guard mis-classified fully-paid invoices as `partially_paid` (`partial_payment_covers_multiple_invoices_correctly`). Now refreshes from the DB first.
- The overdue evaluation used the `is_overdue` accessor, which returns false while the model is still marked paid — reverting from paid always chose `sent`. Now evaluated directly.
Same three guards applied to `Bill::updateStatusFromPayments()`.



---

## Commits on `Accounts-payable-fixes`

| Commit    | Item     | Summary                                                                                           |
| --------- | -------- | ------------------------------------------------------------------------------------------------- |
|           | #1       | `feat(ap): replace Expenses with Bills + Supplier Payments (per-line GST, paid-at-entry, IFRS-aligned categories)` |
|           | #4       | `fix(invoice): un-pay invoices when payments are removed; fix stale-total and is_overdue defects in updateStatusFromPayments` |
