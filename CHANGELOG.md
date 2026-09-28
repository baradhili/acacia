# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased] — 2026-09-28

### Changed — modularisation arc finished: estimates extracted to Modules/Proposals

Quotes/estimates move out of the core app into **Modules/Proposals**, the
ninth shipped module, following the Practice/Taxation precedent — every
URL and route name unchanged, so bookmarks, views and the CRM
proposal-stage hand-off keep working. The controller, models and views
move verbatim (git mv); the sidebar Estimates item re-registers from the
provider at its old CoreNav position; the estimates tables stay in the
core squashed schema (the Reconciliation bank-tables precedent). One
seam needed pinning: documents rows written before the move store the
polymorphic type as the legacy `App\Models\Estimate` class string, so
the provider registers a morph-map alias keeping those rows — and the
DocumentController write path, which builds that string itself —
resolving against the moved class, with no data migration. CRM's lead →
estimate link becomes a module-to-module soft dependency (`Route::has`
guards in the lead view, a graceful fallback in the proposal-stage
shortcut); `Client::estimates()` — unused — is dropped so the core holds
no core-to-module relation. The module is the landing zone for the
proposal-management roadmap now queued in `todo-list.md` (builder,
pricing packages, live preview, branded PDF, client acceptance portal —
grounded in ProposalForge and propsly).

### Fixed — a zeroed line discount no longer leaves a stale amount

`EstimateItem::calculateTotals()` only rewrote `discount_amount`
inside the `discount_percent > 0` branch, so dropping a line's
discount percent to zero kept the earlier calculation in the row and
the tax/total rode on it. The amount is now always derived from the
percent — zero percent zeroes it — which is the invariant the moved
model's docblock states (caught in the extraction's review round; no
write path ever set a flat `discount_amount`, so no stored data can
carry the stale shape).

## [Unreleased] — 2026-09-28

### Fixed — registry swaps no longer collide positions

The sibling of the registry-growth hole: a widget unregistered while
a dashboard page rendered (no card in the DOM) but back in the
registry by the time the user clicked Done kept its old preference
row — the cleanup only drops rows for widgets still outside the
registry — with a position index from an earlier sequence. The
complete save renumbered the submitted cards 0..n-1 and left that
stale index alone, so it could collide with a freshly written one
and the dashboard rendered an order the user never saved, with a
success response. A complete save now unplaces registered-but-absent
widgets (`position_y = NULL`): they ride at the tail in registry
order, exactly like a widget the page never placed, and every
non-NULL position after a complete save is unique. Widget identity
was never the issue — ids (class basenames) are stable across
registration changes; the failure was a stale sequence index, so no
id-tracking redesign is needed.

## [Unreleased] — 2026-09-28

### Fixed — registry growth no longer eats the drag order

The count-based full-save check from the second review pass had its
own hole: when a widget registers between a dashboard page load and
Done (a module enabled mid-session), the browser submits every card
it loaded — fewer than the registry now holds — and the check demoted
that save to a patch, dropping the user's drag order while still
reporting success. Completeness is now declared by the client rather
than inferred from counts: the browser sends `complete: true` (its
payload is always its whole dashboard at save time), order is written
when the flag is set, and the widget the page never saw rides at the
tail. Flag-less payloads keep the patch contract — visibility/width
only, positions untouched.

## [Unreleased] — 2026-09-28

### Fixed — dashboard layout review round, second pass

Three more findings, all valid. **Partial saves claimed ordering they
never applied**: numbering the submitted widgets 0..n-1 collided with
the saved positions of widgets the payload omitted, ties silently
fell back to registry order, and the request still reported success.
Ordering now comes from full saves only — a payload naming every
registered widget rewrites the sequence; a partial payload patches
visibility/width and leaves positions alone (graceful, rather than a
422 that would break a client mid-deploy when a widget registers).
**A double Customize click raced the store fetch**: the second
response replaced the store after the first had opened edit mode,
discarding a card the user had already removed while leaving its
catalog row, and stacked a second Sortable instance only the last of
which was cleaned up — the entering path is guarded by a loading
flag and drag-drop destroys stale instances first. **Reset raced
Done's save**: the DELETE could land before a pending POST, which
then recreated the rows the reset had just cleared, reloading with
the saved layout instead of the defaults — the two operations now
disable each other's buttons and bail while the other owns the wire.

## [Unreleased] — 2026-09-28

### Fixed — dashboard layout review round

Six findings from the layout-management review, all valid, each
landing as its own commit. **The span classes never reached the
stylesheet** — Tailwind's scan covers only Blade views, so the card
spans (interpolated from the PHP registry, swapped by the width
cycler in JS) were invisible to it; the built CSS had no
`md/lg:col-span-1/3/4` rules at all, which had also been degrading
the *shipped* grid (Cash Flow's full-width span never existed) — the
six override classes are now safelisted. **A failed save silently
discarded the layout**: Done closed edit mode before the POST
resolved, hiding the only error message; the save now resolves a
boolean and edit mode stays open with the error visible and the
staged layout intact. **Migration edges**: legacy `width = 1` rows
(written by the old schema default, never user choices — the old
save endpoint rejected every payload) are zeroed to "shipped span" on
the way up, and rollback back-fills NULL `position_y` rows before the
column loses NULL instead of dying on them. **The full save** now
writes its row updates and the stale-widget cleanup in one
transaction, so a failure can't commit a half-moved layout; its
payload must be a list of distinct registry widgets, and its cleanup
targets widgets that left the registry — a partial save rewrites only
what it mentions. **Hidden
widgets kept their query cost**: the store rendered every removed
card via `@widget` on each dashboard load; the page now ships only
the cheap catalog rows and a `hidden-widgets` endpoint serves the
rendered cards the first time edit mode opens (a failed fetch aborts
entering edit mode with a visible error rather than opening it with
inert Add buttons).

## [Unreleased] — 2026-09-28

### Changed — every widget string through the translator

All 15 widget views (11 core — including the unregistered Quick
Actions/Welcome orphans — plus Practice's two, Taxation's GST widget
and Crm's pipeline) now render via `lang/en/widgets.php` keys instead
of hard-coded text; the widget PHP classes' own emitted strings went
too (AR aging bucket labels, "No Project"/"Unknown" name fallbacks).
Registered cards' headers reuse the registry's `labels.*` keys, so a
card's title and its edit-mode catalog entry can never drift apart.
`en` is the complete base per the translation policy; `en_AU`
overrides only the keys that genuinely differ — "Customise Dashboard"
(owed from the layout-management batch), "AR Ageing Summary" and
"Ageing Bucket" — everything else falls back per key. A rendered-
dashboard test pins that behaviour both ways. No visible change under
`en`; under the app's `en_AU` locale the aging widget now spells
correctly.

## [Unreleased] — 2026-09-28

### Added — per-user dashboard layout management

The dashboard's edit mode (profile menu → Customize Dashboard) now
manages a full per-user layout, not just drag order: widgets can be
**reordered** by dragging (SortableJS, as before), **removed** (a ✕
on each card in edit mode) and **added back** from a "Removed widgets"
catalog under the grid — removed cards park in a hidden store
server-side, so adding one back is a DOM move, not a fetch. **Resize**
ships at the column-span level: a width button on each card cycles
the shipped span → half → full width, persisted per user
(`widget_preferences.width`, 0 = the registry's shipped span; the
freeform drag-resize idea stays a future todo). A **Reset to default**
button clears the saved layout. Layouts are per user and render
server-side (`App\Support\WidgetLayout` merges the widget registry
with the preference rows), so the order no longer flashes from
localStorage on load.

Fixing persistence also fixed the old save path, which POSTed an
`{order}` payload the validation rejected — layouts never reached the
database and lived only per browser. `position_y` became nullable
(NULL = never placed by a full save → registry order) and the `width`
default dropped to 0, so single-widget patches can't jump a widget to
the grid front or silently read as a width override; full saves drop
rows for widgets that left the registry (a disabled module's), and
widget names are validated against the registry so junk ids never
persist. The registry (`App\Support\Widgets::add`) gained a label
translation key rendered in the catalog, and Crm's pipeline widget
got a deliberate registry position (130, the tail) instead of the
default 0 that jumped it ahead of the shipped grid. New UI strings go
through the translator (`lang/en/widgets.php`, first real `en_AU`
override file: "AR Ageing"). On whether widgets belong in modules:
module-owned widgets already live in their modules and register from
their providers — the boundary is right; the core widgets are
core-domain (AR, cash flow, P&L, invoicing) and stay in `app/Widgets`.

## [Unreleased] — 2026-09-28

### Removed — the reconciliation auto-create service methods

`autoCreateCashReceipts`/`autoCreatePurchases` and their single-line
variants (`createCashReceiptFromBankTransaction`,
`createPurchaseFromBankTransaction`), with the helpers only they used
(`findClientForTransaction`, `findSupplierForTransaction`,
`suggestExpenseAccount`, `postPaymentToIFRS`) — retired per
maintainer decision: no auto-create. They had no callers since the
Match screen took over creating what a line pays for, and they were
broken against real imported debits until the magnitude fix bought
them one last stay (see the entry below). The matching capability is
untouched — every method the match screen, auto-matcher and learning
loop call survives, and the module suite exercises them end to end.
The one test that depended on auto-create was rewritten to prove the
learned rule resolves payers no name matching could, against the
match pass instead.

### Changed — test suite hardening: CI, clock-proof fixtures, payroll HTTP coverage, route smoke

The general testing review's fixes, three of which caught live bugs.
**CI exists now** (`.github/workflows/ci.yml`): one sqlite job running
`php artisan test` on every push and PR — the suite previously ran
only on the dev server. The job builds the frontend assets first
(`public/build` is gitignored, so a fresh runner would have thrown
missing-manifest on every layout render) and pins its actions to tag
SHAs so a moved upstream tag cannot change what executes. **Time-bomb
fixtures defused**: the reconciliation strict-matcher tests and the
IFRS financial-report tests posted into FY2026 while reading
`now()`-derived fiscal years, so they would have died the day the
clock closed FY2026 — they pin the clock with `travelTo` like the
payroll BAS-labels tests already did (which the review had wrongly
flagged; the audit cleared every other hardcoded-2026 file as pinned,
pure-function or FY-independent). **Payroll's money-moving routes are
tested over HTTP** (create, payslip add/remove — including the staff
gate — process, reverse, delete, the create form's validation) — and
immediately caught a live bug: `addPayslip` read its nullable
`hours`/`gross` keys unguarded, so an hours-based payslip 500ed the
route. **A route smoke test** walks every parameterless GET route as
an admin asserting nothing 500s — its first run caught the CRM
lead-create screen, which forced `lead` null into the shared form and
crashed on the first attribute read; only the deliberately guarded
screens (dividends aborts without a company profile) may answer 404.
Cleanup: the dead matcher methods
`calculateMatchScore`/`getMatchingCandidates` are deleted with the
`method_exists` assertions that kept them nominally alive,
`PaymentTest`'s `void()` tautology went the same way, and phpunit's
coverage source now includes `Modules/` while excluding the module
test directories from the denominator. Every class this arc touched
carries a class docblock (seven gained one, written from verified
behaviour); the ~83 pre-convention core classes still without are
queued on todo-list. Full suite: 994 tests.

### Fixed — legacy reconciliation tests resolved; auto-create purchases take the debit magnitude

The three reconciliation test files excluded per-file in phpunit.xml
(ReconciliationMatching, ReconciliationService, AutoCreateCashReceipt
— 91 tests, 15 failing after the directory-wide exclusion let them
drift) are deleted. Their still-current coverage now lives in
Modules/Reconciliation/Tests/MatchingTolerancesAndMaintenanceTest: the
strict matcher's reference/amount/date evidence and tolerance
boundaries, the ignore/restore lifecycle with its history trail,
auto-created receipts and purchases (guards, client scoping,
paid-at-entry IFRS posting), and merchant-to-expense-account
suggestions. Coverage of removed behaviour went with the files —
`calculateMatchScore`/`getMatchingCandidates` now have no callers at
all (deletion candidates, noted on todo-list). Deleting the files also
unmasked a real defect the old positive-amount fixtures hid:
`createPurchaseFromBankTransaction` fed a Wise debit's stored negative
amount straight into the bill line, so the paid-at-entry path died on
"Allocation amount must be greater than zero" — it now categorises and
pays the magnitude, the convention the learned matcher already used.
The per-file exclusions are gone from phpunit.xml; the full suite
(988 tests) runs everything.

### Added — locales structure and a translation policy

`lang/` lands with the framework's `en` base (auth, pagination,
passwords, validation — editable in-repo now) and a deliberately empty
`en_AU` override directory that takes effect via `APP_LOCALE=en_AU`,
per-key fallback to `en` making a partial override complete by
construction. AGENTS.md makes translator use mandatory for new or
edited user-facing strings (`__()` / `trans_choice`; legacy strings
are deliberately not bulk-converted, only as their screens are
touched). The `.env.example`, the README example and the local `.env`
align on the underscore form `en_AU` — the dash form the README once
showed would never resolve the override directory. No behaviour
change: the override is empty and the strings are the framework's own
(full suite green under the new locale).

### Added — quarterly PAYG-I accrual journal

The BAS settlements screen gained a PAYG instalment accrual card:
record the quarter's estimate journal — Dr income tax expense (8400) /
Cr income tax payable (2240) — computed as the quarter's instalment
income × the ATO-notified instalment rate, finally putting the dead
`bas.installment_rate` config stub (BAS_INSTALLMENT_RATE) to work;
while the rate is unset the card shows a configuration hint and the
accrual refuses. Instalment income is the quarter's cash-basis
assessable income on the company tax report's Item 6 basis —
revenue-account ledger legs from bank-settled transactions, netted
credits-minus-debits so the GST back-out legs net out. The credit
lands on 2240, which the payg_instalment BAS settlement then nets, so
accrue → settle is the full quarterly workflow the September PAYG
settlement work left open. Accruals snapshot the income and rate they
were computed from (the rate normalized to the snapshot's four
decimals so it always explains the posted amount), refuse a duplicate
for a live quarter — checked inside the posting transaction behind an
entity row lock, so concurrent requests cannot double-post (reverse
and re-accrue after backdated revenue instead) — respect the
locked-period/closed-year guards, and reverse with a mirrored journal
dated the quarter end like settlements, but never while a settlement
still covers the accrual (reverse the settlement first, so the ATO
payment is not stranded behind a fictitious overpayment).

### Changed — reporting split: ReportController decomposed, AU statutory reporting extracted to Modules/Taxation

The 2098-line report god controller is broken along its four domains —
TimeReportController, FinancialStatementController,
LedgerReportController, TaxReportController, BusinessReportController —
with every public URL and route name unchanged, so bookmarks, views and
tests are untouched. GST/BAS/company-tax reporting then moved out of
core into **Modules/Taxation** (TaxReportController,
BasSettlementController, BasSettlementService, the
BasSettlement/BasStatement models, exports, config and views; the table
migrations stay in the core schema like Reconciliation's bank tables).
W1/W2 (Payroll) and franking (Shares) become module-to-module
`class_exists` soft deps. The split surfaced and fixed a latent Nav
bug: module providers boot before the core registers its dropdowns, so
an `addTopbarChild` targeting a core-owned dropdown silently no-opped.

### Added — Skills module (ported from resource_mgr)

A searchable skill library (name, category, description) whose entries
link to payroll payees at a proficiency level
(beginner/intermediate/advanced/expert) and to catalogue services as
required skills. Unlike upstream — loose JSON lists of skill-name
strings — both sides are foreign-keyed link tables with cascading
deletes. The Payroll Employee and core Service models stay untouched;
the module owns both pivots (the ProjectStaff pattern).

### Changed — module boundary repairs

Shares-owned views (the dividend-statement PDF and email, the
franking-disclosure PDF) now ship inside Modules/Shares under its
feature dirs instead of core's `reports/pdf/` and `emails/`; and
`ResolvesReportingContext::ifrsEntity()` requires the authenticated
user's own entity — a report 404s rather than borrowing
`IfrsPosting::resolveEntity()`'s posting fallback (first entity, for
unauthenticated jobs), which used to silently serve an entity-less
user that entity's balances (review finding). Enforced before any
controller runs by a new `entity` middleware on the core and Taxation
report/settlement routes — the IFRS EntityScope would otherwise fatal
on their queries — and regression-tested.

## [Unreleased] — 2026-09-26

### Added — employee resumes module

baradhili/laravel-resume incorporated as **Modules/Resumes**: upload a
JSON Resume v1.0.0 (modified schema) against a payroll payee, tailor it
to keywords (any/all match, project cross-references, emptied sections
stripped), and export as filtered JSON, LaTeX source, a
LuaLaTeX-compiled PDF or a PHPWord DOCX. Upload and delete are limited
to admins and the owning employee. No raw upload is kept on disk —
every export regenerates from the stored `parsed_data` (the vendored
schema is committed, never fetched at runtime).

### Changed — every staff-side user is a payroll payee

A module UserObserver (observing the core User) creates each new
user's linked payee record — name, email and entity from the user — so
staff can hold resumes and everyone appears in payroll.
`payroll:sync-users` backfills users created before the observer
(idempotent; run manually after upgrades), the employees screen gained
a Login column, and the previously broken `Employee::user()` relation
now resolves. Portal-client users are skipped defensively — the client
role is slated for removal (todo-list).

### Changed — Employees topbar section

Resumes slots into a new Employees dropdown owned by Payroll in the
topbar (sidebar entry dropped), evening out the dropdowns. An AGENTS.md
agent guide and refreshed module/ops docs landed alongside.

## [Unreleased] — 2026-09-22

### Fixed — supplier payments double-applied GST (with books repair)

Line items were saved twice — once explicitly after `addVat()` and
again by the transaction's `saveLineItems()` — and the package's
second `applyVats()` firstOrCreate missed the stored (rounded) tax
whenever the GST component carried more than four decimal places,
inserting a duplicate applied-vat row and posting the GST legs twice.
The redundant saves are gone from all four posting paths (client
payments, supplier payments, reversal journals, bill reallocation),
and the new `ifrs:repair-duplicated-gst` command removes the duplicate
rows and posts one correcting journal per affected transaction — dated
in the original period, or the current one when that period is closed.

### Added — admins can correct a posted payment's method

Bank-side relabels are metadata-only (they all credit the same bank
account); anything involving the employee-reimbursement method — or a
different employee — unwinds the posted entry (mirrored reversal
beside the original, prepayments voided and recreated) and re-posts on
the corrected leg. Non-admins keep the lock; amount and date cannot
change in the same request.

### Added — the reconciliation screen's book side

Every IFRS ledger movement on a bank account that no matched bank line
has reconciled, traced to the payment that posted it (client, supplier
or reimbursement payment) or labelled by transaction type, with the
PAY/SPAY reference linked to its ERP record. Reversal pairs sharing a
reference net to zero and drop off (a fully reversed payment never
waits for a bank line); listings and match candidates scope to the open
financial year (honouring the open-year pin), so closed-year history
never queues for reconciliation.

## [Unreleased] — 2026-09-21

### Added — employee expense reimbursements (paid-by-employee supplier payments)

Expenses an employee paid out of pocket are now captured without ever
setting the employee up as a supplier: record the bill from the real
supplier as always, then record its supplier payment with the new
"Paid by employee" method and pick the employee (Payroll's employee
list). The capture sits **pending approval** — nothing reaches the
ledger, and its bill stays unpaid — until an admin/accountant approves
it, at which point it posts `Dr Expense / Dr GST Receivable / Cr
Employee Reimbursements Payable (2280)` instead of touching the bank.
Paying the employee back is a new **Reimbursement** payment
(`Dr 2280 / Cr Bank`) that the bank reconciliation matches against the
transfer — manually, by id, or via the learned counterparty rules
(debit bank lines now surface supplier payments and reimbursements).
A pending-approval filter, Approve & Post / Void actions, and an
"owed to employees" summary cover the workflow; a Reimbursements
nav entry groups it. Guards: method/employee lock once posted,
reimbursements cannot exceed what is owed, and an already-reimbursed
capture cannot be voided (void the reimbursement first). The
`ifrs:post-payments` backfill now retries reimbursement payments too
and skips anything still pending approval. Account 2280 is seeded on
fresh installs and created lazily on existing ones.

## [Unreleased] — 2026-09-18

### Added — BAS report leads with the prior-year unsettled line

The report table opens with the prior year's unsettled BAS position
(every column shown) and recording a settlement surfaces the roll-in,
so cross-year netting is visible where the decision is made.

### Fixed — bank lines match payments, not documents

Auto-matching paired bank lines with invoices/bills directly,
bypassing the payment layer (and missing supplier payments entirely);
it now matches on the payment records that actually moved the bank,
including supplier payments.

### Fixed — CRM pipeline widget renders in the standard dashboard card
style; bill-payment edit page restores its document uploads.

## [Unreleased] — 2026-09-17

### Changed — modularisation (nwidart/laravel-modules)

The shell now exposes akaunting-style registration contracts — a nav
registry replaced the 58 hardcoded sidebar/topbar links and the
dashboard grid renders a widget registry — so modules contribute UI
without touching core views. Payroll+PSI, Reconciliation and
Shares/franking/dividends were extracted with history preserved
(core:true, only disableable), with cross-module seams degrading via
`class_exists`. The admin Modules screen enables/disables, updates
git-installed modules, uninstalls (migrations rolled back, directory
deleted — refused for core) and installs from a GitHub URL via
clone→validate→move→migrate→enable with nothing left behind on
failure. `modules_statuses.json` ships committed. Authoring doc:
docs/modules.md; plan: .zcode/plans/modularisation.md. Remaining
extractions: Estimates (BAS/tax landed since as Modules/Taxation), and
pushing the verified payroll subtree split (split/erp-payroll branch)
to its own repo (open on todo-list).

### Added — CRM module (sales funnel)

Leads with a guarded funnel (new → contacted → qualified → proposal →
won/lost; loss reasons, re-open), estimated value + win probability,
source, owner and the plan; the index shows stage counts, open
pipeline value, probability-weighted forecast and overdue follow-ups.
Activities (call/email/meeting/note/task) log per lead. Winning
converts a proposal lead to a Client (details carried, lead stays
linked) — the ERP seam — and a proposal-stage lead has an
estimate shortcut. Targets: monthly sales goals (admin/accountant)
measured against won-lead value. First module authored in place with
its migrations shipping inside the module. Not yet: email/calendar
integrations, per-owner targets (open on todo-list).

### Added — estimates: sections, optional extras, services linkage

Estimate lines carry a Section label (consecutive same-label lines
group under a heading) and can be flagged Optional extras — excluded
from the committed subtotal/tax/total, quoted alongside, and only
invoiced when Convert to Invoice ticks "include optional items". Lines
reference the catalogue Service (service_id kept through
description/price tailoring, untied if the catalogue entry is deleted)
and the forms offer a per-line Service select. Drive-by fix: the
estimates edit view never existed — edit and duplicate 500'd.

### Fixed — cancelling an invoice releases its time entries

Invoiced state is derived, never stored: `TimeEntry::invoiceItem()`
only sees items on non-cancelled invoices, so cancelling releases the
entries automatically; every consumer reads that relation (unbilled-time
widget, dashboard, the create-from-time-entries picker, the unapprove
guard).

## [Unreleased] — 2026-09-16

### Added — manual bank reconciliation with a learning automatcher

Pending bank lines gained a Match screen (candidates ±14 days across
payments/invoices/bills/ledger entries, a reference/counterparty
search that ignores the amount, match-by-id) plus Unmatch. Matching
learns: the counterparty is remembered with the client/supplier it
resolved to (`reconciliation_counterparty_rules`); when the strict
±$0.01/±3-day ledger pass misses, the auto-matcher pairs later lines
from the same counterparty with a fresh unconsumed payment or bill of
theirs, and the auto-create receipt/bill flows resolve the counterparty
from the rule before name matching. Three legacy reconciliation test
files stay excluded per-file in phpunit.xml (ReconciliationMatching,
ReconciliationService, AutoCreateCashReceipt — they drifted while the
whole directory was excluded); repairing them is a separate cleanup
(open on todo-list).

### Added — PAYG instalments; income-tax settlements drive the franking account

BAS settlements gained a `payg_instalment` type alongside
gst/payg_withholding/income_tax (instalments prepay income tax so both
settle 2240; the balance-based netting catches any mixture). Settling
the income-tax types now drives the franking account in the same
transaction — paying credits it (TC), a refund debits it (RF), reversal
mirrors back out — while GST and PAYG withholding stay gated out. The
BAS report shows W1/W2 from processed pay runs attributed by pay day
(draft runs don't count) and freezing snapshots them. Not yet: the
quarterly PAYG-I accrual journal (Dr tax expense / Cr 2240) computed
from the `bas.installment_rate` config stub — dead config until it
lands (open on todo-list).

### Added — company bank details

Company Details gained bank account name, BSB and account number;
invoices print them as a Payment Details block (PDF in the notes
styling, screen as the matching card; blank details omit the block).

### Changed — time entries go against a project only

The Client and PO selects left the entry form (read-only displays
filled from the chosen project), ad-hoc client and internal time are
refused, and the saving hook derives client_id and purchase_order_id
from the project. Projects must link one of their client's POs
(enforced server-side), the link mirrors onto
purchase_orders.project_id, and manual "allocate time to PO" is gone —
an entry's PO comes solely from its project. Historical project-less
entries stay as history.

### Changed — navigation: a Setup dropdown in the topbar; BAS
Settlements moved from Reports to Accounting.

## [Unreleased] — 2026-09-10

### Added — ABN/ACN/TFN formatting and normalisation

`App\Support\AuNumbers` displays ABN 2-3-3-3, ACN 3-3-3, TFN 3-3-3
(3-5 for 8 digits); validation accepts the usual spaced entry; Client,
Supplier, CompanyProfile, CompanyShareholder and payroll Employee
models normalise to bare digits on save and expose formatted
accessors. Displays updated across contacts, the shareholder
register, the invoice PDF and company tax report (CSV exports keep
bare digits).

### Added — negative adjustment lines and separate GST override

Item unit prices may be negative on bills and invoices (a negative
price, 0%-rate line adjusts the ex-GST subtotal); a `gst_override`
column sets a line's GST explicitly — negative for downward
adjustments — so a zero-priced override line moves only the GST.

### Added — ledger retention pruning

`ledger:prune` (scheduled yearly, or by hand; --dry-run, --years)
removes the double-entry trail of CLOSED financial years that ended
before today−N years, after writing an opening-balance snapshot at the
prune boundary so `OpeningBalances::balanceAt()` returns identical
figures for every later date. Open years are never pruned; business
documents (invoices/bills/payments) are kept as the GST/audit record.
N = entity_settings.retention_years, managed on the Administration
page (default 7).

### Fixed — credit notes clear overdue; cancelled invoices owe nothing

Issuing a credit note against an invoice un-marks it (overdue →
sent/partially_paid) and keeps the overdue marker off while the note
stands; voiding the note returns the invoice to normal dunning.
Cancelled invoices return amount_due 0 regardless of total/allocations.

### Changed — every user is created linked with an entity

The admin user form requires an Entity (enforced on store+update), the
users index shows the entity column, and self-registration links the
new user to the instance's entity.

### Changed — navigation: financial statements linked into the IFRS
Reports section (Balance Sheet was implemented but unreachable); the
topbar gained Reports, Shares and Accounting dropdowns; an Admin
section in the profile dropdown carries Administration, Backups,
Users and the rarely-executed Setup & maintenance links that left the
sidebar.

## [Unreleased] — 2026-09-09

### Added — Australian payroll and personal services income

Employees/directors/contractors master data; pay runs with payslips —
PAYG withheld from the ATO NAT 1004 Schedule 1 weekly coefficient
tables, SG 12% on OTE from 1 Jul 2026 (11.5% before; payday super),
director fees withhold and earn super, labour-only individual
contractors earn super; closely-linked and PSI flags snapshot onto
payslips. Processing posts three journals (Dr Wages+Super expense /
Cr PAYG 2210 + Cr Wages Payable 2235 + Cr Super Payable 2220 + Cr
Bank) with reversal and locked-period guards. The PSI screen runs the
80% rule over time-entry-backed invoice income by client, the PSB
Results Test checklist gates PSI mode, attribution nets PSI received
against wages paid to PSI workers, and PSI-mode deduction guidance
follows. Not yet: STP lodgement (data is STP-shaped), hard-blocking
PSI-disallowed bill categories, the fortnightly-specific coefficient
table. Extracted into Modules/Payroll with the modularisation below.

### Added — shares show the value holdings are carried at

holdingsByClass() aggregates each holding's book value (amount_paid,
falling back to quantity × unit_price) with the average unit price;
shown on the shareholder register, the shareholder screen's current
holdings and a per-transaction Value column.

### Added — services catalogue; project timesheet report

Services CRUD (name, description, standard hourly rate — nullable for
fixed-fee, 4dp) at /services, admin/accountant only. The Project
Timesheet report filters by client/project/date and sums hours and
amounts by week (Monday starts) and by calendar month side by side;
approved entries only.

### Added — unlodged GST on the dashboard and GST report

The dashboard widget reads the ledger via
BasSettlementService::position() — the same balances the BAS
settlement screen nets — showing the net to pay/refund plus both
sides; the GST/BAS report gained an "Unlodged GST position" card.

### Changed — bank reconciliation works from CSV; Wise API removed

processImport really imports (the bank's current
transaction-history.csv format handled: IN/OUT directions, multi-
currency card spend, REFUNDED/zero rows skipped, NOTPROVIDED
references cleared; re-uploads skip already-imported rows), and the
reconciliation screen is real (pending/matched/ignored counts,
Auto-match and Ignore actions). The Wise API integration is gone
entirely (WiseService, reconcile:wise command + schedule, config, env
keys, tests) — CSV import is the only feed.

### Changed — time-entry uniqueness and unapproval

One entry per staff member per client per day (internal time exempt),
and approved entries gained an Unapprove action returning them to
draft for editing — refused while allocated to a non-cancelled
invoice; cancelling the invoice releases it.

### Fixed — backup and BAS settlement hardening

Backups: runAndPrune() serialises on a cross-process atomic lock
(overlapping runs report "already running"), BackupSetting became a
database-enforced singleton (fixed key + unique index; the loser of the
create race re-reads the winner's row), the SQLite fallback dumps
through the live connection (WAL contents captured; the torn-page raw
file copy is gone) and archive stamps carry milliseconds plus a random
suffix. BAS: the settlement screen derives one as-at date across
positions/filter/form, unfreezing aborts 404 for another entity's
statement, and reversals validate the period guard and post inside a
transaction. The backup runbook documents restores for both SQLite
archive formats.

## [Unreleased] — 2026-09-04

### Added — BAS settlements and frozen quarters

Settlements gained a type (gst / payg_withholding / income_tax): the
single liability accounts play both netting roles, so a debit balance
(an overpayment) settles as an ATO refund; account codes configurable
via env. The settlement screen shows all three unsettled positions
with a type selector. Freezing a quarter from the BAS report snapshots
its figures (G1/G10/G11/1A/1B/net) into a bas_statements row — the
report, PDF and FY totals prefer the frozen figures, so backdated
postings can never rewrite a lodged BAS; refreeze recaptures live
figures, unfreeze returns the quarter to recomputation.

### Added — backups

`backup:create` command (MySQL via mysqldump, SQLite via VACUUM INTO
snapshot, both gzipped) plus a tar.gz of the public storage disk land
in {BACKUP_PATH}/db and /files, pruned per type. Frequency
(daily/weekly/monthly) and backups-kept live in backup_settings,
managed on the Backups admin page, which lists archives and runs the
command on demand; scheduled daily 04:00 with the command's internal
due-check honouring the frequency. Runbook documents restore.

### Fixed — company logo on documents

The uploaded logo renders on PDF invoices and on the bill record, the
invoice header became profile-driven, and invoices say "Tax Invoice".

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

### Fixed — invoicing and time-entry guards

The time-entry consumption race on invoice creation closed (a concurrent
request could invoice the same entries), the create-from-time-entries flows
screen for single-client entries and refuse client mismatches, a purchase
order's effective client always wins the client_id sync, and only open or
partially-used POs can be invoiced. The time-entries picker gained
"select all".

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

## [Unreleased] — 2026-08-26 to 2026-08-29

Late-August features that predate the dated entries above.

### Added — staged year-end close

Trial close (checklist + proposed closing entries, `fiscal-year:trial` /
Financial Years page) → approval (accountant/admin ≠ requester) → execute
(`fiscal-year:close`) posts two JEs (reference FY-CLOSE-{year}) transferring
every P&L balance to Retained Earnings, marks the IFRS ReportingPeriod
CLOSED, locks the year's app periods and ensures next-FY exists OPEN.
Reopen mirrors the entries back out. Closed FYs block payment/bill-payment
dates, voids and unapplies with friendly errors; reports exclude FY-CLOSE
references from P&L movement so historical statements survive the close,
and the balance sheet stops adding on-the-fly profit once the FY is closed.
CLI `--force` bypasses the approval workflow.

### Added — bill editing and deletion

Unpaid bills (draft/open/overdue with $0 paid) are fully editable; any bill
can be deleted from any status — payments are voided and their ledger shares
reversed via BillLifecycleService; paid bills are corrected by unapplying
the payment first. Supplier payments got a Void action and posted payments
can no longer be hard-deleted (which orphaned their journal entries).

### Added — shares, franking account and dividends

Shareholder registry with holdings per class, share classes on the Setup
screen, franking account with AASB 1054 disclosure, and dividend
declarations distributing per share class with franking — later extended
by opening balances, carried values and the statement workflows documented
above.

### Fixed — cash-basis GST

Cash-basis GST reporting now recognises settlements correctly across the
purchase and sales cycles (the Fix/ifrs-gst-cash-basis PR).

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
