# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased] — 2026-09-03

### Fixed — a sole accountant/admin can approve their own year-end close

The year-end close's four-eyes rule (requester ≠ approver) dead-ended a
single-user company: with no other accountant/admin to hand the approval to,
the request waited forever. Submitting now routes the approval back to the
requester when they are the only accountant/admin — the submit confirmation
says so and the trial page shows them the Approve button (with a note
explaining why) instead of the waiting message. When a second
accountant/admin exists, the four-eyes rule still applies and the requester
still cannot approve their own request.

### Added — year-end close writes next year's opening balances (single-entry migration model)

Opening balances are entered by hand exactly once, at migration into the
system; every later opening set is generated. Executing the year-end close
now writes FY {year+1}'s opening set from the closing position — one Balance
row per balance-sheet account (Retained Earnings included, carrying the
closed result), dated the year end, stamped `FY-CLOSE-{year}-OB`. Opening
sets behave as **superseding snapshots**: reports (trial balance, balance
sheet, account statement, company tax item 8, the close's own trial math)
read as-at positions through `OpeningBalances::balanceAt()` — the latest
snapshot dated on/before the as-at date plus ledger movement strictly after
it — so a migration set (debit current assets, matching credit to equity)
shows in the tax report's item 8 asset labels, multiple sets can never
double-count (previously they all summed), and ledger activity predating a
migration set is superseded rather than counted on top. Reopen removes the
generated set (a re-close regenerates it) and the Opening Balances screen
renders close-generated sets read-only.

### Added — company tax report equity reconciliation (supplementary)

Beyond the ATO labels, the company tax report now reconciles equity: brought
forward (opening snapshot at FY start), the year's result (net profit
excluding year-end closing entries), dividends paid on the 8-J/8-K basis,
and closing equity — on screen, in the PDF and in the Excel/CSV exports.

## [Unreleased] — 2026-09-02

### Fixed — profile photo upload no longer 500s when the storage link is missing

updatePhoto() tried to create the public/storage symlink itself; the web
user cannot write public/ on this deployment, and Laravel turns symlink()'s
warning into an exception — the upload died with a 500 after the file was
stored but before the user row was saved, so the photo never appeared. The
symlink attempt is now best-effort (missing link logs a warning and never
fails the upload), old-photo deletion goes through the Storage disk instead
of unlinking through the public path, and the avatar accessor checks the
public disk rather than public/storage so it resolves regardless of the
link. Deployments should still run `php artisan storage:link` (done here).
Three new photo tests cover upload/replace/delete and validation.

### Fixed — Company Details page can edit the company and trading names

The page displayed the legal name read-only and had nowhere to record a
business name. The Company Identity card now leads with an editable
**Company name** (required — persisted to the IFRS entity, whose name
feeds statutory outputs like the Company Tax Return identification and
dividend statements) and an optional **Trading name** (new nullable
`company_profiles.trading_name` column, shown as "trading as" in the
page header when set; blank clears it).

### Fixed — franking account opening balances can now be entered

The franking account screen gains an **Opening balance** entry type (OB) for
the brought-forward position when backfilling: dated the day before the
financial year it opens (e.g. 30 Jun 2025 for FY2025), at most one per
financial year, credit for surplus franking or debit for a brought-forward
deficit. The entry carries forward into the year it opens (and every later
year) without appearing in that year's movement summary or creating a
phantom year in the selector; the estimated flag never applies to it.

### Added — admin-settable "currently open year" (backfill gateway)

- New Administration page (`/administration`, linked from an "Administration"
  section in the admin's profile dropdown) where an administrator pins the
  currently open financial year — any FY from the calendar-derived one back
  seven years. The pin lives in the app-owned `entity_settings.open_year`
  column; null follows the calendar, and a pin left outside the sliding window
  by the clock is ignored on read (the page explains the expiry).
- Pinning a past year creates its OPEN `ifrs_reporting_periods` row — the
  gateway for backfilling history: the year becomes selectable on the Opening
  Balances page (which now defaults to the open year) and transactions dated
  in it can post. Closed years must be reopened before pinning; the year-end
  close still governs which years are locked.
- `FiscalYearService::currentYear()` honours the pin, so the Financial Years
  page, the unclosed-prior-year warning and the close-workflow guards anchor
  on the working year. Clock-derived statutory logic (BAS/report year pickers,
  period locks, package mechanics) is unchanged.

## [Unreleased] — 2026-08-27

### Added — prepaid subscriptions, domain names and licence fees (AASB/IFRS)

- Bill lines gain a **Prepaid** tick with a service period (start/end) and an
  amortise-to account; paying the bill debits the prepaid asset (460) and spawns an
  amortisation schedule. The `prepayments:amortise` runner (scheduled daily 03:30)
  posts one entry per due month-end (Dr expense / Cr prepaid, final month absorbs the
  rounding remainder), crossing financial years as needed — closes the todo-list item
  about subscriptions spanning FYs.
- Idempotency by `unique(prepayment_id, period_date)`; per-month same-date reversals;
  void reverses every posted month. `/prepayments` review screens plus a Prepayment
  Amortisation Schedule report (screen + PDF).
- **Domain name registry** (`/domains`): initial purchases capitalise to intangible
  170 via a capital bill line; indefinite life by default (no amortisation); finite
  life creates a schedule (Cr 170 / Dr 7910); renewals are guided to 7510 and warned
  against on the bills forms.
- New seeded accounts: 460 Prepaid Subscriptions, 170 Domain Names, 7510 Domain
  Renewal Expense, 7910 Amortisation Expense. Existing databases run
  `php artisan db:seed --class=IFRSSeeder` (idempotent).

### Changed — purchase GST now debits GST Receivable (430)

- New seeded Vat `I "GST Input 10%"` → account 430; `BillPayment::postToIFRS()`
  prefers it for supplier-payment GST legs and falls back to `G`/2200 when not
  seeded. **Closes the long-standing follow-up from #22.**

## [Unreleased] — 2026-08-16

### Changed — migrations squashed (50 files → 9)

Database was empty (seeded setup data only), so the migration history was collapsed into
final-schema files with **no schema change** — verified byte-identical on MariaDB
(normalised `mariadb-dump --no-data` diff of all 55 tables before/after; full test suite
green; `migrate:fresh --seed` reproduces the exact seeded state). Deploying this branch
requires a one-time `php artisan migrate:fresh --seed` (structure-only DB).

- `0001_01_01_000000` users/password_reset_tokens/sessions (salary, position, phone,
  profile_photo folded in; `deleted_at` deliberately added in `2026_08_16_000001` so it
  lands after the IFRS package's `entity_id`/`destroyed_at`, preserving column order)
- cache / jobs / spatie permission files kept as-is
- `2026_08_16_000001` clients/suppliers/vendors (addresses, custom fields, logos, soft
  deletes) + users soft deletes
- `2026_08_16_000002` projects/project_staff/purchase_orders/time_entries (incl. the
  formerly-separate time-entry FKs and projects.purchase_order_id)
- `2026_08_16_000003` invoicing: invoices (recurring columns, unsigned `ifrs_invoice_id`),
  invoice_items, credit notes, payments (status, credit_note_id, unsigned
  `ifrs_receipt_id`), payment_allocations (no allocation_type), estimates
- `2026_08_16_000004` bills/bill_items/bill_payments/bill_payment_allocations
- `2026_08_16_000005` documents/bank_transactions/reconciliation_history/audit_logs/
  fiscal_periods/widget_preferences
- Deleted outright: the expenses trio and the expenses→bills conversion (expenses tables
  are never created — bills replaced them), and the data-only `drop_viewed_invoice_status`

## [Unreleased] — 2026-08-15

Accounts-payable rework: Expenses replaced by Bills + Supplier Payments
(branch `Accounts-payable-fixes`, Todo_list.md #1).

### Added — Bills / accounts payable

- **Allocations default to 100% of the nominated document** (#2): the client-invoices /
  supplier-bills JSON endpoints return `amount_due` (outstanding balance); ticking an
  invoice/bill on the payment-create forms pre-fills its allocation with the outstanding
  balance (previously the full total, over-allocating partially-paid documents) and the
  payment amount auto-syncs to the sum of checked allocations; the allocate modals
  pre-fill with the nominated document's outstanding balance capped at the payment's
  unallocated amount.

- **Bills subsystem** mirroring Invoices: `bills`/`bill_items`/`bill_payments`/
  `bill_payment_allocations` tables; `BILL-{Y}-####` and `SPAY-{Y}-####` numbering with the
  unique-violation retry; invoice-style state machine (`draft → open → partially_paid → paid`,
  plus `overdue`/`cancelled`); draft-only edit/delete with item-id-preserving upsert; payment
  edit guards (amount ≥ allocated, supplier frozen while allocated); allocation, void and
  over-allocation semantics identical to AR.
- **Paid-at-entry mechanism** (#1): "already paid" checkbox on the bill form (parking,
  entertainment, online purchases) creates the bill, payment, full allocation and ledger
  posting in one transaction. The Wise bank-debit auto-create path reuses the same flow.
- **Per-line GST treatment** (#1): each bill line is GST 10% inclusive or GST-free
  (bank fees, rego, input-taxed supplies), enforced per item — not per bill.
- **Expense categories = IFRS chart-of-accounts entries**: each line picks an expense
  account, so bill categories and journals align by construction. Seeded two new accounts
  (Meals & Entertainment 5500, Phone & Internet 7250).
- **`BillPayment::postToIFRS()`**: Cr Bank / Dr expense (net) / Dr GST per line — taxable
  lines post tax-inclusive with the GST 10% Vat (package nets the GST out), GST-free lines
  post in full with no Vat; allocation amounts apportioned across bill items in cents.
  Calls `post()` (not just `save()`) so ledger rows are actually written.

### Changed

- **Legacy Expenses converted and dropped** (#1): each expense → a bill + one line item
  (category mapped to the seeded IFRS expense account); paid expenses also get a payment +
  allocation carrying the old `ifrs_transaction_id` so nothing double-posts. Bank
  transactions, reconciliation history and documents are repointed at the bills; receipt
  files became Document rows (same morph invoices use — no more bespoke receipt upload);
  `expenses` table dropped.
- **Downstream consumers re-pointed to bills**: dashboard cash-flow/PnL (also fixes the
  daily-cash-flow outflows always being 0 — it queried a nonexistent `paid_at` column on
  expenses), GST/tax-summary input tax now reads bill items (per-line treatment),
  expenses-by-category report groups by IFRS account, Wise reconciliation creates paid
  bills (also fixes supplier lookup querying `clients` instead of `suppliers`), audit
  logging and document factories follow the new models.

### Fixed

- **`Invoice::updateStatusFromPayments()` could never un-pay an invoice** (#4, pre-existing —
  failed at HEAD). Three defects: the no-payments branch excluded `STATUS_PAID`, so
  removing/voiding allocations left paid invoices paid forever (now reverts to
  overdue/sent and clears `paid_at`); the status decision used a possibly-stale in-memory
  `total` (item roll-ups persist on a different instance), tripping the `$total > 0` guard
  and marking fully-paid invoices partially_paid (now refreshes from the DB first); and the
  revert used the `is_overdue` accessor, which reports false for still-paid models (now
  evaluated directly). Same guards applied to `Bill::updateStatusFromPayments()`, which
  already reverts paid bills when payments are removed.

## [Unreleased] — 2026-08-12 to 2026-08-15

Invoice, payment, and IFRS accounting subsystem review (branch `fixes-2`).
Item numbers refer to `Todo_list.md`.

### Fixed — financial correctness

- **Invoice `subtotal` stored the tax-inclusive amount** (`78bb956`, #1). `recalculateTotals()`
  summed the tax-inclusive line `total` into `subtotal`. Now stores the pre-tax line amount
  (qty × price − discount), so `subtotal + tax == total` on the show/PDF views and
  `SUM(subtotal)` reports true pre-GST revenue.
- **IFRS cash receipts posted broken ledger entries and never posted GST** (`0aac670`, #7).
  `Payment::postToIFRS()` and the duplicate `ReconciliationService::postPaymentToIFRS()` used
  nonexistent `LineItem::DEBIT`/`::CREDIT` constants (fatal `Error` not caught by
  `catch (\Exception)`), non-fillable keys silently dropped by Eloquent, the wrong date key,
  and no main account so double-entry could not balance. Rewritten: Dr Bank (main account) /
  Cr Revenue (net) / Cr GST Payable via `vat_inclusive` + `addVat(GST 10%)`; correct
  `transaction_date`; defensive entity resolution for queued jobs; `catch (\Throwable)`.
  Migration `2026_08_12_110000` fixes the `ifrs_receipt_id`/`ifrs_invoice_id` column types.
- **Expense ledger posting had the same fatal IFRS defects** (`861824f`, #22) — rewritten to
  Dr Expense / Cr Bank with correct mechanics. *(Follow-up open: GST on purchases should debit
  GST Receivable (430); needs a second seeded Vat.)*
- **Account Schedule report fatal-errored** (`861824f`, #23). Used nonexistent
  `LineItem::DEBIT`/`::CREDIT` and the wrong date column. Now splits debit/credit by the
  `credited` boolean and queries `transaction_date`.
- **Split payments were double-counted** (`0bbeced`, #17). The removed `Invoice::payments()`
  HasManyThrough returned full `Payment.amount` instead of the amount allocated to each
  invoice, overstating "paid" in the invoice show view and the sales-by-customer report.
  Both now use `allocations` (the per-invoice amount).
- **Overdue scope included effectively-paid invoices** (`c83e774`, #10). `scopeOverdue` now
  requires an outstanding balance (`total > SUM(allocations)`) for past-due sent/partially_paid
  invoices, so paid-but-not-marked invoices no longer show as overdue forever.

### Fixed — data integrity

- **Invoice/payment numbers were not concurrency-safe** (`450b8c5`, #2). Two simultaneous
  requests could generate the same number (500 for the loser). Added
  `createWithUniqueNumber()` retry loop (catches the unique-constraint violation, regenerates);
  all six production create sites routed through it.
- **Voiding/deleting a payment did not revert invoice statuses** (`2031cd3`, #5).
  `updateStatusFromPayments()` ran while allocations still existed, so statuses were never
  recomputed. Order fixed: delete allocations first, then recompute.
- **Payments could be recorded against draft/cancelled/paid invoices** (`c7a5ae3`, #6).
  `recordPayment()` now rejects non-outstanding invoices.
- **`allocateToInvoice()` silently clamped over-allocations** (`572a8c9`, #4) and returned an
  unsaved `PaymentAllocation` on zero. Now throws `InvalidArgumentException`; all callers run
  inside transactions so the throw rolls back cleanly.
- **Voiding a payment clobbered invoice status** (`e717b23`, #9). When payments drop to zero
  the status is re-derived from `due_date` (past due → overdue) instead of blindly forcing
  `sent`; draft/cancelled/paid are never clobbered.
- **Editing an invoice severed its time-entry links** (`67ab762`, #19). `update()` deleted and
  recreated all line items. Now upserts by item id, preserving `time_entry_id`; edit form
  round-trips the item id.
- **Payment edits could corrupt allocations** (`ca5f177`, #12/#13). Rejects amounts below the
  allocated total (no more hidden negative `unallocated_amount`) and blocks `client_id`
  changes while allocations exist.
- **IFRS seeder never associated the admin user with the entity** (`45e632b`, #24). `User`
  fillable omitted `entity_id` (mass-assignment dropped it), `UserSeeder` pre-created the
  admin so `firstOrCreate` never applied it, and the `_TEMP_` entity hack created the currency
  before the entity. Restructured per the package README (entity → currency → link), plus
  seeds the `ReportingPeriod` required for any posting.

### Changed

- **FIFO payment allocation removed** (`ef3ec69`, #11). Allocation is manual-only;
  `allocation_type` column dropped, reallocate-fifo route/action and UI removed.
- **`viewed` invoice status removed** (`f671cb3`, #3). State machine collapsed to
  draft → sent → partially_paid/overdue → paid. Existing `viewed` rows backfilled to `sent`;
  dead `InvoiceViewedNotification` chain deleted. *(Audit found no client portal ever
  existed — clients only ever received the emailed PDF.)*
- **`markAsSent()` now validates through the state machine** (`6aefc99`, #15).
- **Currency symbol sourced from config** (`c5203c3`, #21) — `formatted*` accessors read
  `config('australian.currency.symbol')` instead of hardcoding `A$`.
- **`getClientInvoices` filters in SQL** (`c5203c3`, #21) instead of loading then filtering in PHP.

### Removed

- Dead `preventRecalculation` recursion guard (`9d1cd4c`, #8) — the flag was checked but never
  set; recursion safety is now solely (and explicitly) the `withoutEvents`/`updateQuietly`
  persistence in `recalculateTotals()`.
- Unreachable partially-paid→paid auto-promotion in `transitionTo()` (`b84508f`, #14), which
  also collapsed two `update()` calls into one.
- Duplicate PO used-amount recalculation (`f2bb1b0`, #18) — observer owns status-driven
  recalcs, model owns total-driven recalcs.
- Redundant `Invoice::payments()` relation (`0bbeced`, #17).

### Deferred

- **#20** `createFromTimeEntries` / `createFromPurchaseOrder` — views were never created, so
  both routes 500; POST never created anything. Time entries out of scope for now.
- Wiring `postToIFRS()` into manual payment entry (only the bank-reconciliation path posts).
- GST Receivable on purchases (from #22).

### Tests

Tests added or updated throughout, including: forced-collision retry tests (#2), ledger
balance + GST-split assertions with a seeded IFRS stack (#7), payment edit guard tests
(#12/#13), item-edit round-trip preservation (#19), recursion safety (#8), overdue-scope
exclusion (#10), and `IFRSSeederTest` seeding in production order with idempotency (#24).
