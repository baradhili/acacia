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

### 4. ✅ `Payment::allocateToInvoice()` clamps to `unallocated_amount` and silently drops over-allocations

**File:** `app/Models/Payment.php`
`$amount = min($amount, $this->unallocated_amount)` silently truncated an over-allocation to the available balance, and when amount ≤ 0 it returned an unsaved `new PaymentAllocation()` (no id) that looked like success to callers.
**Fix:** `572a8c9` — replaced both with `InvalidArgumentException`: amount must be > 0 and must not exceed `unallocated_amount`. All four production callers (`PaymentController::store`/`allocate`, `InvoiceController::recordPayment`, `CreditNote::applyToInvoice`) run inside `DB::beginTransaction()` blocks so the throw rolls back cleanly; the UI paths already pre-validate (`max:amount_due`, `max:unallocated_amount`), so this is defense-in-depth for direct/programmatic callers. Updated the one test that relied on the silent clamp to expect the throw.

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
> 
> - **7a.** `app/Models/Expense.php:194-208` — `Expense::postToIFRS()` has the **same** `LineItem::DEBIT`/`::CREDIT` + `'type'` + `'tax_rate'=>0` fatal-error family of bugs. Same fix pattern applies. See item 22.
> - **7b.** `app/Http/Controllers/ReportController.php:625-626` — uses `IFRS\Models\LineItem::DEBIT`/`::CREDIT` for balance reporting (summing debits/credits). Same nonexistent constants — any report hitting this path fatal-errors. See item 23.

## High-Severity Logic Issues

### 8. ✅ `InvoiceItem::saved` → `recalculateTotals` → `static::saved` recursion risk

**Files:** `app/Models/Invoice.php`, `app/Models/InvoiceItem.php`
The `preventRecalculation` runtime flag was checked in the invoice `saved` handler but **never set anywhere** in the codebase. Recursion was avoided only because `recalculateTotals` saves via `withoutEvents`/`updateQuietly` (suppressing the `saved` event) — correct by accident.
**Fix:** `9d1cd4c` — deleted the dead `static::saved` hook and the vestigial flag reference. The `withoutEvents`/`updateQuietly` persistence in `recalculateTotals()` is now the sole (and intentional) recursion guard, documented explicitly with a warning not to replace it with a plain `update`/`save` without a real re-entry guard. Added a recursion-safety test that calls `recalculateTotals()` directly on an invoice with items (verifying correct pre-tax/GST/total independently of which hook fired) and then does a plain `save()` to confirm no stack overflow — the scenario that would fail loudly if a future auto-recalc `saved` hook were added without a real guard.

### 9. ✅ `updateStatusFromPayments()` blindly resets `partially_paid`/`overdue` to `sent`

**File:** `app/Models/Invoice.php`
When `amountPaid <= 0`, status reverted to `STATUS_SENT` regardless of prior state — an `overdue` invoice lost its flag and a `draft` that had allocations removed became `sent`.
**Fix:** `e717b23` — when payments drop to 0, never clobber `draft`/`cancelled`/`paid`; otherwise overdue (past `due_date`) beats sent, using the existing `getIsOverdueAttribute`. Also guarded the fully-paid branch with `$total > 0` so a zero-total invoice isn't marked paid by accident. Updated the one test whose post-removal expectation (due_date 5 days past) changes from SENT to OVERDUE under the corrected logic.

### 10. ✅ `scopeOverdue` includes invoices with `amount_due == 0` still marked sent

**File:** `app/Models/Invoice.php`
The scope flagged any sent/partially_paid invoice past its due_date, including invoices fully paid via allocations whose status hadn't been flipped to paid — those showed as "overdue" forever.
**Fix:** `c83e774` — added a balance check: for invoices with `total > 0`, require an outstanding balance (`total > SUM(payment_allocations.amount)`) via a correlated subquery. Zero-total invoices fall through to the status/due_date path (preserves existing behavior). Added a test that builds a sent-but-fully-paid invoice past due and asserts it is excluded from the overdue scope.

### 11. ✅ Payments could be created without being allocated, then never posted to IFRS (FIFO removal)

**Files:** `app/Models/Payment.php`, `app/Http/Controllers/PaymentController.php`, views, routes, migration
`PaymentController::store` supported `allocate_type: 'no'` (unallocated payment) and FIFO allocation; nothing ever reconciled unallocated payments, and `postToIFRS` was never called from any controller (dead path).
**Fix:** `ef3ec69` — removed FIFO allocation entirely (manual-only now). The `postToIFRS` method itself is now correct (see item 7, fixed in `0aac670`), but it is still **not called** from `PaymentController::store` / `InvoiceController::recordPayment` (manual payment entry never posts to the ledger — only the bank-reconciliation path does). Wiring it in is a follow-up.

### 12. ✅ `PaymentController::update` allows changing `amount` below allocated total

**File:** `app/Http/Controllers/PaymentController.php`
Validation was `'amount' => 'required|numeric|min:0.01'` with no relationship to `allocated_amount`, so shrinking a payment below its allocated total made `unallocated_amount` go negative (hidden by the accessor's `max(0, ...)`) and broke downstream allocation logic.
**Fix:** `ca5f177` — added a guard rejecting `amount < allocated_amount` with a clear error pointing the user to remove allocations first.

### 13. ✅ `PaymentController::update` does not re-validate allocations when `client_id` changes

**File:** `app/Http/Controllers/PaymentController.php`
Changing `client_id` left existing allocations pointing at invoices belonging to the old client — no cascade or guard. The `allocate()` action already enforced client-match, but `update` didn't.
**Fix:** `ca5f177` — added a guard blocking a `client_id` change when allocations exist (must remove them first). Also removed the dead pre-update `updateAllocatedInvoicesStatus()` call (it recomputed against the old state = no-op); the meaningful recompute after the update is kept. Two tests added covering each guard.

## Medium Issues

### 14. ✅ `Invoice::transitionTo` does two separate `update()` calls

**File:** `app/Models/Invoice.php`
Two `update()` calls when transitioning to PAID (one for status, one for `paid_at`) — two DB writes and two rounds of model events. Also the partial-paid→paid auto-promotion block was unreachable.
**Fix:** `b84508f` — collapsed into a single `update()` that stamps `paid_at` together with the status when transitioning to PAID. Removed the unreachable auto-promotion block (`amount_due <= 0` means the invoice is fully paid, so the caller transitions to PAID, not PARTIALLY_PAID).

### 15. ✅ `markAsSent` bypasses the state machine

**File:** `app/Models/Invoice.php` (`markAsSent`)
Raw `update()` instead of `transitionTo()`, ignoring the `$transitions` guard. The `markAsViewed` half of this item was removed in #3 / `f671cb3`.
**Fix:** `6aefc99` — `markAsSent()` now calls `transitionTo(STATUS_SENT)` (validates the transition) and stamps `sent_at` only on success. The sole caller (`InvoiceController::send`) already pre-checks `status === DRAFT`, so the validated transition is always draft → sent.

### 16. ✅ `getIsOverdueAttribute` compares a Carbon date to a date string

**File:** `app/Models/Invoice.php`
Mixed `lt(Carbon)` vs `lt(string)` (`due_date->lt(now()->toDateString())`), relying on Carbon's coercion.
**Fix:** `b4e69c3` — switched to `due_date->isBefore(now()->startOfDay())` — Carbon-to-Carbon at day granularity, consistent with `scopeOverdue` (which uses `due_date < today`). Same semantics (an invoice due today is not overdue), just explicit and consistent.

### 17. ✅ `Invoice::payments()` HasManyThrough double-counted split payments

**Files:** `app/Models/Invoice.php`, `app/Http/Controllers/InvoiceController.php`, `app/Http/Controllers/ReportController.php`, `resources/views/invoices/show.blade.php`
The original report flagged the relation as a redundant double-load; the real bug was worse — `payments()` returned full `Payment.amount`, not the amount allocated to *this* invoice, so any payment split across invoices was double-counted in both the show view (`$payment->amount` per payment) and the sales-by-customer report (`$inv->payments->sum('amount')` for `total_paid`).
**Fix:** `0bbeced` — show view now iterates `$invoice->allocations` and shows `$allocation->amount` (the correct per-invoice portion) via `$allocation->payment` for number/date; dropped the redundant `payments` eager-load. Report switched to `$inv->allocations->sum('amount')`. Removed the now-unused `payments()` relation and its `HasManyThrough` import. `allocations()` is now the single source of truth for payment application.

### 18. ✅ `InvoiceObserver` + `refreshPurchaseOrderUsedAmount` duplicate work

**Files:** `app/Observers/InvoiceObserver.php`, `app/Models/Invoice.php`
Both the observer (`updated`) and `Invoice::recalculateTotals()` (via `refreshPurchaseOrderUsedAmount`) called `PurchaseOrder::recalculateUsedAmount()` on total changes, so a normal line-item edit recalc'd the PO twice. Critically, `recalculateUsedAmount` filters by status (excludes draft/cancelled), so status transitions also affect the used amount — and those don't flow through `recalculateTotals`.
**Fix:** `f2bb1b0` — made the observer the single owner of status-driven recalcs and the model the single owner of total-driven recalcs. The observer now handles `purchase_order_id` reassignment (old + new PO) and `status` changes (cancel/send); `total` changes are left solely to `recalculateTotals`/`refreshPurchaseOrderUsedAmount`. Eliminates the double recalc on line-item edits without losing status-change coverage.

### 19. ✅ `InvoiceController::update` deletes and recreates items, severing `time_entry_id` links

**Files:** `app/Http/Controllers/InvoiceController.php`, `resources/views/invoices/edit.blade.php`
`$invoice->items()->delete()` then recreated with new ids; new items got `time_entry_id = null` because the form didn't pass it. **Editing a draft invoice silently unlinked its time entries.** (Note: the `time_entries.invoice_item_id` back-reference column the original report assumed doesn't actually exist in the migrations — the link is one-way, `invoice_items.time_entry_id` — so the concrete damage was the lost `time_entry_id`, which is what the fix addresses.)
**Fix:** `67ab762` — replaced delete-all-recreate with an upsert: collect submitted item ids, delete only items the user removed (scoped to the invoice), `update()` existing items by id (preserving `time_entry_id` unless explicitly changed), `create()` new ones. Added `items.*.id` / `items.*.time_entry_id` validation and a hidden `items[][id]` input per existing row in the edit view so the id round-trips. Round-trip test added: edits a time-entry-linked item and asserts id + `time_entry_id` survive.

### 20. 🚫 Won't fix (deferred) — `createFromTimeEntries` doesn't actually create

**Files:** `app/Http/Controllers/InvoiceController.php`, `routes/web.php`, missing views
Investigation confirmed the bug is worse than reported: the POST route (`invoices.create-from-time-entries.store`) points to the **same render-only method** as the GET, so posting just re-renders and never persists. Additionally the view itself (`resources/views/invoices/create-from-time-entries.blade.php`) **does not exist**, so both GET and POST have been 500ing with `ViewNotFoundException` — the feature is unreachable/broken end-to-end, and nothing in the UI links to these routes (no tests either). The sibling `createFromPurchaseOrder` has the same problem (`invoices/create-from-po.blade.php` also missing; route `purchase-orders/{po}/create-invoice`).
**Decision:** deferred per maintainer — time entries are out of scope for now. When revisiting: create the two missing views, split the POST into a real store handler that builds `InvoiceItem`s via `InvoiceItem::createFromTimeEntry()` (which already exists, unsave()-ed, for exactly this), and link `time_entry_id` on each item.

## Low-Severity / Style

### 21. ✅ Misc cosmetic / consistency

**Fix:** `c5203c3` (plus two sub-items resolved elsewhere):
- ✅ `InvoiceItem::createFromTimeEntry` docblock now states explicitly that it builds an **unsaved** item and the caller must persist it.
- ✅ All 10 hardcoded `'A$'` literals in `formatted*` accessors now read `config('australian.currency.symbol', 'A$')` (key already existed at `config/australian.php:67`), across Invoice, Estimate, Payment, PaymentAllocation, InvoiceItem, EstimateItem, CreditNoteItem.
- ✅ `Payment.php` `use HasFactory;` moved above the STATUS_ constants (was interleaved), matching Invoice.php's convention.
- ✅ `PaymentController::getClientInvoices` outstanding-balance filter pushed from PHP (`withSum` + `->filter()`) into SQL via the same correlated-subquery `whereRaw` as `scopeOverdue`; dropped the now-unneeded `withSum`.
- ✅ *(resolved by #15 / `6aefc99`)* `markAsSent` now validates via `transitionTo()`, eliminating the guard inconsistency with `send`'s pre-check.
- 🚫 *(deferred with #20 — time entries out of scope)* `time_entry_ids` validated in `store` but never linked to created items.

## Newly discovered during the #7 IFRS fix (same `LineItem::DEBIT/CREDIT` family of bugs)

### 22. ✅ `Expense::markAsPaid()` had the same fatal IFRS posting defects

**Files:** `app/Models/Expense.php`
Shared the exact defects fixed in #7 (`Payment::postToIFRS`, `0aac670`): nonexistent `LineItem::DEBIT`/`::CREDIT` constants (fatal `Error` not caught by `catch (\Exception)`), non-fillable `'type'`/`'tax_rate'` keys silently dropped, wrong `'date'` key (should be `transaction_date`), and no main account so double-entry couldn't balance.
**Fix:** `861824f` — rewritten to mirror the Payment fix: JournalEntry main account = expense (`credited=false` → Dr Expense), bank as the credited line item (→ Cr Bank), `transaction_date`, defensive `resolveIFRSEntity()` helper, catch broadened to `Throwable`.
**Note:** GST on purchases is **not** split out here. The seeded `GST 10%` Vat (code `G`) is linked to GST Payable (2200, a liability) — that's the *sales-side* account. Reusing it on an expense would credit the wrong account (reducing GST Payable when it should debit GST Receivable/430 as an asset). Posting GST Receivable on purchases remains a **follow-up** — it needs a second seeded Vat wired to account 430.

### 23. ✅ `ReportController::accountSchedule()` used nonexistent `LineItem::DEBIT`/`::CREDIT`

**File:** `app/Http/Controllers/ReportController.php` (`accountSchedule`)
`$allItems->where("type", IFRS\Models\LineItem::DEBIT)` / `::CREDIT` — the constants don't exist on `LineItem` (only `Balance::DEBIT/CREDIT` do), so any account-schedule report rendering that reached this code fatal-errored with `Undefined constant`. The `LineItem` has no `type` column at all. Also queried `transaction.date`/`whereBetween("date")`, but the Transaction column is `transaction_date`.
**Fix:** `861824f` — debit/credit totals now derived from the line item's `credited` boolean (`false` = debit, `true` = credit), verified against the `ifrs_line_items` migration. All `date` references switched to `transaction_date`. Grep confirms zero `LineItem::DEBIT`/`::CREDIT` references remain in `app/` outside explanatory comments. (The `$item->tax_rate` references at lines 681/836/982 are on `InvoiceItem`/`Expense` — our own models, which do have `tax_rate` — so those were not bugs and are untouched.)

### 24 ☐ Update IFRSSeeder.php so it creates the base entity and associates it with the admin user

### 25 ☐ Replace existing "Expenses" UI and linked files.

Existing Expenses is confusing. change to "Bills" and make it similar to "Invoices/Payments" - except Bills are invoices _from_ a supplier. There should also be an option to record Bills that were paid immediately - such as things like parking, entertainment, online purchases etc. Make sure Expense categories align to Journals

### 26 ☐ Replace "php artisan test" with a command that runs unit tests and uses behat to run feature tests

## Suggested Priority

| #        | Severity | Area                                                                       | Status              |
| -------- | -------- | -------------------------------------------------------------------------- | ------------------- |
| 1        | Critical | `subtotal` stored tax-inclusive                                            | ✅                   |
| 5        | Critical | Void/delete/reallocate mis-ordering                                        | ✅                   |
| 6        | Critical | Payment recordable against draft/cancelled                                 | ✅                   |
| 7        | High     | IFRS posting double-counts / ignores GST                                   | ✅                   |
| 2        | Critical | Invoice/payment number races                                               | ✅                   |
| 3        | Critical | `markAsViewed` state-machine bypass (resolved by removing 'viewed' status) | ✅                   |
| 22, 23   | High     | Same IFRS `LineItem::DEBIT/CREDIT` family of bugs (Expense + Reports)      | ✅ (found during #7) |
| 11       | High     | Unallocated/FIFO payment plumbing                                          | ✅ (FIFO removed)    |
| 4, 9, 19 | High     | Silent data loss / status corruption                                       | ✅                   |
| 12, 13   | High     | Payment edit guards                                                        | ✅                   |
| 8        | Med      | Dead `preventRecalculation` recursion guard                                | ✅                   |
| Others   | Med/Low  | Maintainability / consistency                                              | ☐                   |

---

## Commits on `fixes-2`

| Commit    | Item | Summary                                                                                    |
| --------- | ---- | ------------------------------------------------------------------------------------------ |
| `78bb956` | #1   | `fix(invoice): store pre-tax subtotal instead of tax-inclusive amount`                     |
| `2031cd3` | #5   | `fix(payment): recompute invoice status after allocations are deleted`                     |
| `ef3ec69` | #11  | `refactor(payment): remove FIFO allocation`                                                |
| `c7a5ae3` | #6   | `fix(invoice): reject payments against draft/cancelled/paid invoices`                      |
| `0aac670` | #7   | `fix(ifrs): post correct ledger entries and GST on cash receipts`                          |
| `450b8c5` | #2   | `fix(invoice,payment): make number generation concurrency-safe via unique-violation retry` |
| `f671cb3` | #3   | `refactor(invoice): remove the 'viewed' status and dead client-portal scaffold`            |
| `861824f` | #22, #23 | `fix(ifrs): correct Expense ledger posting and account-schedule reporting` |
| `572a8c9` | #4   | `fix(payment): throw on over-allocation in allocateToInvoice` |
| `e717b23` | #9   | `fix(invoice): re-derive status from due_date when payments removed` |
| `67ab762` | #19  | `fix(invoice): upsert items on edit to preserve item id and time_entry_id` |
| `ca5f177` | #12, #13 | `fix(payment): guard amount and client edits when allocations exist` |
| `9d1cd4c` | #8   | `refactor(invoice): remove dead preventRecalculation recursion guard` |
| `c83e774` | #10  | `fix(invoice): scopeOverdue excludes effectively-paid invoices` |
| `b84508f` | #14  | `refactor(invoice): collapse transitionTo into one update; drop unreachable auto-promotion` |
| `6aefc99` | #15  | `fix(invoice): route markAsSent through the state machine` |
| `b4e69c3` | #16  | `refactor(invoice): compare Carbon-to-Carbon in is_overdue` |
| `0bbeced` | #17  | `fix(invoice): use allocations (not payments) for per-invoice amounts; drop payments() relation` |
| `f2bb1b0` | #18  | `refactor(invoice): dedupe PO used-amount recalc between observer and model` |
| — | #20 | Won't fix (deferred) — time entries out of scope; feature views missing, would 500 |
| `c5203c3` | #21  | `refactor(misc): currency from config, createFromTimeEntry docblock, cosmetic and query cleanups` |
