# Invoice & Payment Subsystem — Bug List

Status legend: ☐ open · ✅ done

## Critical (data correctness / financial integrity)

### 1. ✅ `Invoice::recalculateTotals()` mislabeled `subtotal` — stored tax-inclusive totals
**File:** `app/Models/Invoice.php` (`recalculateTotals`)
**Fix:** `78bb956` — subtotal now derived as pre-tax line amount (`qty × price − discount`); `total = subtotal + tax_amount`.
**Effect:** `Subtotal + GST = Total` now adds up on show/PDF views; `ReportController::sum('subtotal')` reports true pre-GST revenue.

### 2. ✅ `generateInvoiceNumber()` / `generatePaymentNumber()` are not concurrency-safe
**Files:** `app/Models/Invoice.php`, `app/Models/Payment.php`
Two simultaneous requests read the same last row and +1, producing duplicate invoice/payment numbers; the `unique()` constraint prevented duplicate data but 500'd the loser of the race.
**Fix:** `450b8c5` — added `createWithUniqueNumber()` to both models: a 5-attempt loop that catches the unique-constraint `QueryException` (SQLSTATE 23000 / MySQL 1062, driver-agnostic via Laravel's wrapper) and retries. Each retry re-enters the `creating` hook and regenerates from the now-higher max, so it lands on the next number. Generator logic and the `creating` hook are unchanged. All six production create sites (`InvoiceController::store`/`recordPayment`, `PaymentController::store`, `ReconciliationService`, `ProcessRecurringInvoices`, `Estimate::convertToInvoice`, `CreditNote::applyToInvoice`) routed through the helper; grep confirms zero bare `::create(` sites remain in `app/`. Chose the retry-on-violation approach (per discussion) over a heavier sequence table — the unique constraint is already the source of truth, and the path is low-frequency. Tests force a real collision and assert the helper lands on a distinct number.

### 3. ✅ `markAsViewed()` could violate the state machine (resolved by removing the 'viewed' status)
**Files:** `app/Models/Invoice.php`, `app/Http/Controllers/InvoiceController.php`, `app/Notifications/InvoiceViewedNotification.php` (deleted), `app/Services/InvoiceNotificationService.php`, + 9 query-list sites, views, route, migration, tests
An audit found **no actual client portal exists** in this codebase (no public/guest routes, no token columns, no portal views/controllers/nav — clients only receive an emailed PDF). What existed was the staff-side `viewed` status scaffold plus dead notification code around it. The original bug (a `draft → viewed` bypass via the `markAsViewed()` elseif branch, plus `markAsSent`/`markAsViewed` bypassing `transitionTo()`) is moot: the whole `viewed` status was removed.
**Fix:** `f671cb3` — removed `STATUS_VIEWED` entirely and collapsed the state machine to `draft → sent → partially_paid/overdue → paid`. Deleted `markAsViewed()`, the `markViewed` controller action + route, the `InvoiceViewedNotification` class (dead — the service method that fired it had zero callers), and `sendInvoiceViewedNotification`. Dropped `STATUS_VIEWED` from the 9 query-list sites that treated it as outstanding (Client, ARAgingWidget, PnLTrendWidget, MarkOverdueInvoices, DashboardService×2, ClientStatementService, ReconciliationService, PaymentController); `sent` is in every list so no invoice falls through. Removed the 'Viewed' status filter + badge from the index view. New migration backfills any existing `status='viewed'` rows to `'sent'`. Behavior change: invoices that would have been `viewed` now stay `sent` until paid/overdue. **Note:** the `markAsSent` raw-`update()` pattern (bypassing `transitionTo()`) remains — folded into item #15.

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

### 7. ✅ `Payment::postToIFRS()` double-counts revenue and ignores GST
**Files:** `app/Models/Payment.php`, `app/Services/ReconciliationService.php`
Investigation against `ekmungai/eloquent-ifrs` v6.0.0 source revealed the defects were worse than the original review: both the dead `Payment::postToIFRS()` and the live `ReconciliationService::postPaymentToIFRS()` referenced nonexistent `LineItem::DEBIT`/`::CREDIT` constants (fatal `Error` not caught by `catch (\Exception)`), passed non-fillable keys (`type`, `tax_rate`, `date`) that Eloquent silently dropped, never posted GST (no `addVat`), and built the JournalEntry with no main account so double-entry couldn't balance.
**Fix:** `0aac670` — `Payment::postToIFRS()` is now the single source of truth: JournalEntry with main account = bank (`credited=false` → Dr Bank), a single `vat_inclusive` revenue line with `addVat(GST 10% code G)` so the package auto-credits account 2200 for the GST component, `transaction_date` = payment date, and defensive entity resolution (authed user's entity, else first entity) for queued jobs. Catch broadened to `Throwable`. Service delegates to the model; duplicate constants removed. Migration `2026_08_12_110000` fixes `ifrs_receipt_id`/`ifrs_invoice_id` column types (`string` → `unsignedBigInteger`). Test strengthened to seed the IFRS stack and assert the ledger balances + GST split.

> **Sibling bugs found during this fix (not part of #7, tracked below):**
> - **7a.** `app/Models/Expense.php:194-208` — `Expense::postToIFRS()` has the **same** `LineItem::DEBIT`/`::CREDIT` + `'type'` + `'tax_rate'=>0` fatal-error family of bugs. Same fix pattern applies. See item 22.
> - **7b.** `app/Http/Controllers/ReportController.php:625-626` — uses `IFRS\Models\LineItem::DEBIT`/`::CREDIT` for balance reporting (summing debits/credits). Same nonexistent constants — any report hitting this path fatal-errors. See item 23.

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
**Fix:** `ef3ec69` — removed FIFO allocation entirely (manual-only now). The `postToIFRS` method itself is now correct (see item 7, fixed in `0aac670`), but it is still **not called** from `PaymentController::store` / `InvoiceController::recordPayment` (manual payment entry never posts to the ledger — only the bank-reconciliation path does). Wiring it in is a follow-up.

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

## Newly discovered during the #7 IFRS fix (same `LineItem::DEBIT/CREDIT` family of bugs)

### 22. ☐ `Expense::postToIFRS()` has the same fatal IFRS posting defects
**File:** `app/Models/Expense.php:194-208`
Uses nonexistent `LineItem::DEBIT`/`::CREDIT` constants, non-fillable `'type'`/`'tax_rate'` keys, `'tax_rate' => 0` (no GST), and almost certainly the wrong `'date'` key and no main account — same defects fixed in `Payment::postToIFRS()` (item 7, `0aac670`). Any expense marked "paid" that triggers this path will fatal-error. Apply the same fix pattern: main account, `transaction_date`, `vat_inclusive` + `addVat`, `Throwable` catch.

### 23. ☐ `ReportController` uses nonexistent `LineItem::DEBIT`/`::CREDIT` for balance reporting
**File:** `app/Http/Controllers/ReportController.php:625-626`
`$allItems->where("type", IFRS\Models\LineItem::DEBIT)` / `::CREDIT` — the constants don't exist on `LineItem`, so any report rendering that reaches this code fatal-errors with `Undefined constant`. Also references `$item->tax_rate` on IFRS line items which have no such field (line 681, 836, 982). Needs reworking against the actual IFRS ledger/balance API (`Account::closingBalance()`, ledger `entry_type`). Verify with a report test before trusting.

## Suggested Priority

| # | Severity | Area | Status |
|---|----------|------|--------|
| 1 | Critical | `subtotal` stored tax-inclusive | ✅ |
| 5 | Critical | Void/delete/reallocate mis-ordering | ✅ |
| 6 | Critical | Payment recordable against draft/cancelled | ✅ |
| 7 | High | IFRS posting double-counts / ignores GST | ✅ |
| 2 | Critical | Invoice/payment number races | ✅ |
| 22, 23 | High | Same IFRS `LineItem::DEBIT/CREDIT` family of bugs (Expense + Reports) | ☐ (found during #7) |
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
| `0aac670` | #7 | `fix(ifrs): post correct ledger entries and GST on cash receipts` |
| `450b8c5` | #2 | `fix(invoice,payment): make number generation concurrency-safe via unique-violation retry` |

