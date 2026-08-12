# Invoice & Payment Subsystem — Bug List

Status legend: ☐ open · ✅ done

## Critical (data correctness / financial integrity)

### 1. ✅ `Invoice::recalculateTotals()` mislabeled `subtotal` — stored tax-inclusive totals
**File:** `app/Models/Invoice.php` (`recalculateTotals`)
**Fix:** `78bb956` — subtotal now derived as pre-tax line amount (`qty × price − discount`); `total = subtotal + tax_amount`.
**Effect:** `Subtotal + GST = Total` now adds up on show/PDF views; `ReportController::sum('subtotal')` reports true pre-GST revenue.

### 2. ☐ `generateInvoiceNumber()` / `generatePaymentNumber()` are not concurrency-safe
**Files:** `app/Models/Invoice.php:110-125`, `app/Models/Payment.php:67-82`
Two simultaneous requests read the same last row and +1, producing **duplicate invoice/payment numbers** (the `unique()` constraint then 500s one caller). Also fragile if records are deleted (`orderBy('id','desc')` + regex can disagree).
**Fix:** `lockForUpdate()` on the lookup query, a `invoice_sequences`/`payment_sequences` table with row-level locking, or a DB sequence.

### 3. ☐ `markAsViewed()` can violate the state machine and corrupt status
**File:** `app/Models/Invoice.php:321-335`
Per `$transitions`, `draft → viewed` is **not** valid (draft can only go to sent/cancelled). The `draft` branch lets someone bypass sending entirely and mark a draft as viewed. Also: `markAsSent`/`markAsViewed` use raw `update()` and bypass `transitionTo()`, so the `$transitions` guard is ignored — either the guards matter (use them) or they don't (delete the table).

### 4. ☐ `Payment::allocateToInvoice()` clamps to `unallocated_amount` and silently drops over-allocations
**File:** `app/Models/Payment.php:209-240`
`$amount = min($amount, $this->unallocated_amount)` silently truncates instead of erroring. When amount ≤ 0 it returns an unsaved `new PaymentAllocation()` (no id) that looks like success to callers. Return `null` or throw on insufficiency.

### 5. ✅ `Payment::void()` / `destroy()` / `reallocateFifo()` update invoice statuses **before** deleting allocations
**Files:** `app/Models/Payment.php:388-404`, `app/Http/Controllers/PaymentController.php` (`destroy`, `reallocateFifo`)
`updateStatusFromPayments()` reads `allocations()` — at call time the allocations still existed, so status was never actually reverted. (Note: `reallocateFifo` was later removed entirely as part of FIFO removal — see item 11.)
**Fix:** `2031cd3` — capture invoice IDs, delete allocations first, then recompute (matches the existing correct order in `Payment::removeAllocation`).

### 6. ✅ `InvoiceController::recordPayment` allowed payments against draft/cancelled invoices
**File:** `app/Http/Controllers/InvoiceController.php` (`recordPayment`)
No status check — a payment could be recorded against a `draft` or `cancelled` invoice. Also the `max:` validation interpolated a float string (fragile).
**Fix:** `c7a5ae3` — explicit guard rejecting `draft`/`cancelled`/`paid`; `amount_due` cast to `(float)` before interpolation.

### 7. ☐ `Payment::postToIFRS()` double-counts revenue and ignores GST
**File:** `app/Models/Payment.php:307-375`
Journal entry debits bank and credits the **full payment amount** to revenue — but a payment may be partially unallocated or spread across invoices. GST posted with `tax_rate: 0` on both legs, so GST collected is never reflected. Uses `JournalEntry` (generic) rather than an IFRS `Receipt`/`ClientPayment` transaction, hence `ifrs_receipt_id` stores a journal-entry id — confusing. Larger accounting-design issue; scope with whoever owns the books before touching.

## High-Severity Logic Issues

### 8. ☐ `InvoiceItem::saved` → `recalculateTotals` → `static::saved` recursion risk
**Files:** `app/Models/Invoice.php:103-107`, `app/Models/InvoiceItem.php:46-50`
The `preventRecalculation` runtime flag is checked in the invoice saved handler but is **never set anywhere** in the codebase. Recursion is avoided only because `recalculateTotals` uses `withoutEvents`/`updateQuietly` — correct by accident. A future change to `recalculateTotals` could trigger infinite recursion. Make the guard explicit.

### 9. ☐ `updateStatusFromPayments()` blindly resets `partially_paid`/`overdue` to `sent`
**File:** `app/Models/Invoice.php:432-454`
When `amountPaid <= 0`, status reverts to `STATUS_SENT` regardless of prior state. An `overdue` invoice with its payment voided loses the overdue flag; an invoice that was never sent (`draft`) but had allocations removed becomes `sent`. Should restore the *prior* payable status or re-derive from `due_date`.

### 10. ☐ `scopeOverdue` includes invoices with `amount_due == 0` still marked sent
**File:** `app/Models/Invoice.php:393-400`
The scope catches `sent`/`viewed`/`partially_paid` past their due date, but doesn't exclude invoices that are really paid-but-not-marked-paid (`amount_due == 0`). Those show as "overdue" forever. Redundant with `MarkOverdueInvoices` command (status already flipped to `STATUS_OVERDUE`).

### 11. ✅ Payments could be created without being allocated, then never posted to IFRS (FIFO removal)
**Files:** `app/Models/Payment.php`, `app/Http/Controllers/PaymentController.php`, views, routes, migration
`PaymentController::store` supported `allocate_type: 'no'` (unallocated payment) and FIFO allocation; nothing ever reconciled unallocated payments, and `postToIFRS` was never called from any controller (dead path).
**Fix:** `ef3ec69` — removed FIFO allocation entirely (manual-only now). Unallocated-payment reconciliation and the dead `postToIFRS` call site remain open (folded into item 7's IFRS design work).

### 12. ☐ `PaymentController::update` allows changing `amount` below allocated total
**File:** `app/Http/Controllers/PaymentController.php:132-170`
Edit a payment from $1000 → $10 while it has $800 in allocations: `unallocated_amount` goes negative (hidden by `max(0, ...)`), downstream allocation logic breaks. Validate `amount >= allocated_amount`.

### 13. ☐ `PaymentController::update` does not re-validate allocations when `client_id` changes
**File:** `app/Http/Controllers/PaymentController.php:132-170`
Changing `client_id` on a payment leaves existing allocations pointing at invoices belonging to the old client — no cascade or guard. The `allocate` method checks client match, but `update` does not.

## Medium Issues

### 14. ☐ `Invoice::transitionTo` does two separate `update()` calls
**File:** `app/Models/Invoice.php:285-304`
One `update` for status, then another for `paid_at` — two DB writes and two model events. Collapse into one `update(['status' => ..., 'paid_at' => ...])`. Also: the partial-paid→paid auto-promotion on line 299 is unreachable (you can't `transitionTo('partially_paid')` and simultaneously have `amount_due <= 0`).

### 15. ☐ `markAsSent` / `markAsViewed` bypass the state machine
**File:** `app/Models/Invoice.php:309-335`
Raw `update()` instead of `transitionTo()` — see item 3.

### 16. ☐ `getIsOverdueAttribute` compares a Carbon date to a date string
**File:** `app/Models/Invoice.php:257-263`
`$this->due_date->lt(now()->toDateString())` — Carbon coerces, but mixing `lt(Carbon)` vs `lt(string)` is inconsistent. Use `now()->startOfDay()`.

### 17. ☐ `Invoice::payments()` HasManyThrough may double-count
**File:** `app/Models/Invoice.php:152-155`
`->with('payments')` and `->with('allocations')` (as `InvoiceController::show` does) loads the same payment data twice via different paths. Almost always want `allocations` (carries the amount applied to *this* invoice). Consider dropping `payments()` or renaming.

### 18. ☐ `InvoiceObserver` + `refreshPurchaseOrderUsedAmount` duplicate work
**Files:** `app/Observers/InvoiceObserver.php`, `app/Models/Invoice.php:220-225`
Both the observer (`updated`/`created`/`deleted`) and `Invoice::recalculateTotals()` call `PurchaseOrder::recalculateUsedAmount()`. On a normal item save this runs at least twice. Pick one owner.

### 19. ☐ `InvoiceController::update` deletes and recreates items, severing `time_entry_id` links
**File:** `app/Http/Controllers/InvoiceController.php:201`
`$invoice->items()->delete()` then recreates with new ids. Time entries still reference the now-deleted item id via `invoice_item_id`, and new items get `time_entry_id = null` (the update form doesn't pass it). **Editing a draft invoice silently unlinks its time entries.** Use upserts, or carry `time_entry_id` through the form.

### 20. ☐ `createFromTimeEntries` doesn't actually create — it only renders
**File:** `app/Http/Controllers/InvoiceController.php:290-327`
Returns a view with computed totals but doesn't persist anything. The route is wired for GET+POST; the POST handler appears missing. Confirm and add if needed.

## Low-Severity / Style

### 21. ☐ Misc cosmetic / consistency
- `InvoiceItem::createFromTimeEntry` (line 101) builds but doesn't `save()` — fine if callers save, but the docstring doesn't say so.
- `formattedAmount` and every `formatted*` accessor hardcode `'A$'` — pull from a config/i18n layer if currency ever changes.
- `InvoiceController::send` (line 246) requires `status === DRAFT`, but `markAsSent` itself has no guard — inconsistent with `send`'s check.
- `PaymentController::getClientInvoices` filters in PHP after loading — push to a `whereRaw('total - (...) > 0')` or `having`.
- `time_entry_ids` validated in `store` but never linked to created items (no `time_entry_id` passed to `items()->create`) — see item 19.
- `Invoice` uses `const STATUS_*` (good) but `Payment` interleaves `use HasFactory` between constant blocks (`Payment.php:16-21`) — cosmetic.

## Suggested Priority

| # | Severity | Area | Status |
|---|----------|------|--------|
| 1 | Critical | `subtotal` stored tax-inclusive | ✅ |
| 5 | Critical | Void/delete/reallocate mis-ordering | ✅ |
| 6 | Critical | Payment recordable against draft/cancelled | ✅ |
| 2 | Critical | Invoice/payment number races | ☐ |
| 7 | High | IFRS posting double-counts / ignores GST / never called | ☐ |
| 11 | High | Unallocated/FIFO payment plumbing | ✅ (FIFO removed) |
| 4, 9, 19 | High | Silent data loss / status corruption | ☐ |
| 12, 13 | High | Payment edit guards | ☐ |
| Others | Med/Low | Maintainability / consistency | ☐ |

---

## Commits on `fixes-2`

| Commit | Item | Summary |
|--------|------|---------|
| `78bb956` | #1 | `fix(invoice): store pre-tax subtotal instead of tax-inclusive amount` |
| `2031cd3` | #5 | `fix(payment): recompute invoice status after allocations are deleted` |
| `ef3ec69` | #11 | `refactor(payment): remove FIFO allocation` |
| `c7a5ae3` | #6 | `fix(invoice): reject payments against draft/cancelled/paid invoices` |
