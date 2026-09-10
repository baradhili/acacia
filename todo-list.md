# Todo list

- [x] In `@app/Http/Controllers/BackupController.php`:
  Line 46: Update BackupService::runAndPrune() to acquire one shared
  cross-process atomic lock before running the complete backup-and-prune
  operation, including temporary/archive creation and pruning, and release it
  afterward. Return an explicit “backup already running” result when the lock is
  unavailable, then update BackupController::run() and CreateBackup::handle() to
  handle that result without proceeding as if the backup succeeded.
  - (Sep 2026) Done. runAndPrune() now takes a `backups:run-and-prune` atomic
    cache lock (TTL 3600s, matching the process timeouts; released in `finally`)
    around the whole run — archives, temp files, success stamp and prune. When
    the lock is held it returns `already_running: true` with empty
    created/removed. The Backups page "run now" button shows an error flash and
    the `backup:create` command warns and exits without stamping success.
    Covered by BackupTest::test_overlapping_runs_report_already_running_instead_of_backing_up.

- [x] In `@app/Http/Controllers/BasSettlementController.php`:
  Around line 31-36: Update BasSettlementController::index to derive the default
  as-at date once, using the requested as_at when present or the latest completed
  quarter end otherwise, and reuse that value for service->positions(),
  positionAsAt, and defaultAsAt.
  - (Sep 2026) Done. index() derives one `$effectiveAsAt` (requested as_at, else
    the latest completed quarter end from quarterEnds(), else today) and feeds it
    to positions(), positionAsAt and defaultAsAt, so the shown balances, the
    date filter and the settle form default can never disagree. Covered by
    BasSettlementTest::test_the_screen_shares_one_as_at_across_positions_filter_and_form.

- [x] In `@app/Http/Controllers/ReportController.php`:
  Line 1073: Update unfreezeBasQuarter to verify the route-bound BasStatement
  belongs to the authenticated entity before deletion, preferably by loading it
  through the current entity relationship or enforcing the entity-scoped
  authorization policy. Preserve authorized same-entity behavior and add a feature
  test confirming a user cannot unfreeze another entity’s statement.
  - (Sep 2026) Done. unfreezeBasQuarter now aborts 404 unless the bound
    BasStatement's entity matches ifrsEntity() (the caller's entity) — the route
    binding resolves by id alone, so the check stops one entity's admin deleting
    another's frozen quarter; 404 rather than 403 so foreign ids aren't confirmed.
    Same-entity unfreezing is unchanged. Covered by
    BasStatementFreezeTest::test_another_entitys_statement_cannot_be_unfrozen.

- [x] In `@app/Models/BackupSetting.php`:
  Around line 33-37: Update BackupSetting::current() to use a fixed singleton
  key and an atomic fetch-or-create operation, ensuring concurrent callers obtain
  or create the same persisted settings row before BackupService::runAndPrune()
  invokes recordSuccess(). Add a database uniqueness constraint for that key so
  only one BackupSetting row can exist.
  - (Sep 2026) Done. current() now firstOrCreate()s on a fixed
    `singleton_key = 'default'`; concurrent first callers race on a unique index
    (migration 2026_09_09_000001, which also collapses any rows the old
    first()-wins race left), the loser re-reads the winner's row via the
    UniqueConstraintViolationException catch, and recordSuccess() always stamps a
    persisted row. Covered by the two new BackupTest singleton tests.

- [x] In `@app/Services/BackupService.php`:
  Line 224: Replace the raw copy fallback in the backup flow with
  SQLite-consistent handling that includes or checkpoints WAL contents, such as
  reusing dumpSqliteStatements(), so committed data is preserved when VACUUM INTO
  fails.
  Line 60: Update the archive stamp generation in BackupService::runAndPrune()
  to include sufficient uniqueness beyond seconds, such as microseconds and a
  random suffix, so sequential runs cannot reuse database or file archive paths.
  - (Sep 2026) Done. When VACUUM INTO cannot run (e.g. inside a transaction) the
    fallback is now always dumpSqliteStatements() — it reads through the live
    connection so committed rows still in the WAL are captured; the raw
    file copy (which missed WAL contents and could copy torn pages) is gone.
    The archive stamp gained milliseconds and a random hex suffix
    (`Ymd_His_v` + 6 hex chars) while keeping the sortable Ymd_His prefix.
    Covered by test_sequential_runs_in_the_same_second_never_reuse_archive_paths
    (time frozen so both runs share one second); the dump fallback runs in every
    backup-creating test since tests execute inside a transaction.

- [x] In `@app/Services/BasSettlementService.php`:
  Around line 259-269: Wrap the reverseTransaction call and the subsequent
  settlement forceFill/save operation in a single database transaction, preserving
  the existing reversal ID and timestamp updates so both ledger posting and
  settlement state commit or roll back together.
  Around line 249-264: In BasSettlementService::reverse, validate the
  settlement's settled_at date and settlement entity with the existing
  assertDatePostable() guard before calling IfrsPosting::reverseTransaction().
  Preserve the current reversal checks and posting flow, ensuring locked
  FiscalPeriod entries cannot be bypassed.
  - (Sep 2026) Done. reverse() now runs assertDatePostable(settled_at,
    settlement->entity) before posting — a period locked since the settlement
    was recorded refuses with the usual error and nothing posts — and the
    reverseTransaction + reversal-id/timestamp save are wrapped in one
    DB::transaction. Covered by
    test_a_settlement_cannot_be_reversed_into_a_locked_period.

- [x] In `@docs/runbooks/backup-restore.md`:
  Around line 11-12: Update the backup table to list only MySQL and SQLite as
  supported database drivers, then revise the restore procedure to provide
  actionable commands for both SQLite archive formats: .sqlite.gz snapshots and
  .sql.gz textual dumps, while retaining the existing MySQL restore command for
  SQL archives.
  - (Sep 2026) Done. The overview table lists MySQL/SQLite only; the built-in
    command's restore snippet and the Restore Procedures section now give copy-
    paste commands for .sqlite.gz (decompress over DB_DATABASE) and .sql.gz
    (rebuild via sqlite3 from a fresh file, with integrity_check/migrate:status
    sanity checks) alongside the retained MySQL gunzip-into-mysql command, and
    the backup description matches the dumpSqliteStatements fallback and the
    new millisecond+random archive stamps.

- [x] Time entry - must not enter time against an already entered date/client combination - but if it is not allocated to an invoice can unapprove it and edit again - (Sep 2026, branch add-services) Done. One entry per staff member per client per day: store/update are refused with a pointer to the existing entry when the (effective — project precedence) date/client combination is already taken; internal time (no client) is exempt and other staff are unaffected. Approved entries gained an Unapprove action (POST time-entries/{entry}/unapprove, button on the entry screen) that returns them to draft for editing — refused while the entry is allocated to a (non-cancelled) invoice; cancelling the invoice releases it. Covered by TimeEntryLifecycleTest.

- [x] Bank reconciliation doesn't upload - also remove API integration, its too much of a hassle. - (Sep 2026) Done. The upload works: ReconciliationController::processImport now really imports via ReconciliationService::importFromCsv, and the importer handles the bank's current transaction-history.csv download (ID/Status/Direction/Source-Target format — IN credits as target amount, OUT debits as the AUD source amount even for multi-currency card spend, REFUNDED/zero rows skipped, NOTPROVIDED references cleared) as well as the older statement export; already-imported rows are skipped so re-uploading is safe. The reconciliation screen is real now (pending/matched/ignored counts, pending + recently-matched tables, Auto-match and Ignore actions) instead of the "Phase 6" placeholder. Wise API integration removed entirely: WiseService (API client), reconcile:wise command + daily schedule, services.wise config, WISE_* env keys and WiseApiSyncTest are gone; CSV import is the only feed. Fixture: tests/transaction_history_sample.csv (a real export).

- [x] Update dashboard widget and report to pick up unlodged GST balances both receivable and payable
  - (Sep 2026) Done. The dashboard widget (now "Unlodged GST") reads the ledger via
    BasSettlementService::position() — the same balances the BAS settlement screen
    nets — instead of estimating payable from outstanding invoices; it shows the
    net to pay/refund plus both sides (payable · receivable) and flips colour when
    a refund is due. The GST/BAS report gained an "Unlodged GST position" card
    (as at the report end date) with payable, receivable and net rows. Covered by
    tests/Feature/Bas/GstPositionDisplayTest.php.

- [x] shareholders - share held at what value? $10 for 1000
  - (Sep 2026) Done. holdingsByClass() now aggregates each holding's book value —
    SUM(amount_paid, falling back to quantity × unit_price) — with the average
    unit price (1000 @ $10 shows as $10,000.00 @ $10.0000/share), and the
    shareholder register, the shareholder screen's Current holdings card and a
    per-transaction Value column all display it. Covered by
    SharesAndDividendsTest::test_holdings_carry_the_value_they_are_held_at.

- [x] Add handling for payroll - australian rules - handle closely linked people as well, personal services income
  - (Sep 2026) Done per the .zcode/wages_and_psi-spec.md modules A–E. **Payroll** (A/B): employees/directors/contractors
    master data; pay runs with payslips — PAYG withheld from the ATO NAT 1004 Schedule 1 weekly coefficient tables
    (config/payroll.php, 2026-27: scale 2 w/ threshold, scale 1 w/o, 47% no-TFN, weekly-equivalent conversion for
    fortnight/month; fortnightly may differ $1 from the ATO's fortnightly-specific table); SG 12% on OTE from 1 Jul
    2026 (11.5% before; payday super — accrued per run); director fees withhold AND earn super; labour-only
    individual contractors earn super, company contractors don't; closely-linked + PSI flags snapshot onto
    payslips. Processing posts three journals (Dr Wages+Super expense / Cr PAYG 2210 — nets in the BAS settlement
    screen — + Cr Wages Payable 2235 + Cr Super Payable 2220 + Cr Bank) with reversal and locked-period guards.
    **PSI** (C/E): /psi screen — 80% rule over time-entry-backed (service work) invoice income by client, PSB
    Results Test checklist gating PSI mode (entity_settings.psb_results/psi_mode), attribution (PSI received −
    wages paid to PSI workers = net PSI to the individual), and PSI-mode deduction guidance (no occupancy costs,
    no associate payments for non-principal work). Not yet: STP lodgement API (data is STP-shaped), Module D
    hard-blocking of PSI-disallowed bill categories (currently guidance), fortnightly-specific coefficient table.
    Covered by tests/Feature/Payroll/PayrollTest.php + PsiTest.php.

- [x] add crud/ui etc for services controller and model - fix any bugs. - (Sep 2026, branch add-services) Done. Services catalogue (name, description, standard hourly rate — nullable for fixed-fee, 4dp) with full CRUD at /services, admin/accountant only, nav link under Time & Projects; covered by tests/Feature/ServiceTest.php. Skeleton bugs fixed: removed references to the non-existent Skill model and to PhpWord/Markdown (packages not installed — every route fataled); dropped the required_skills JSON (two incompatible shapes between store/index/update); plain `find()` crashes on unknown ids → route-model binding 404s; duplicated conflicting validation → single ServiceRequest.

- [x] Need to handle clients who want timesheet reports for project by calendar month and week sum
  - (Sep 2026) Done. New "Project Timesheet" report (/reports/project-timesheet, nav under Reports): per project,
    filterable by client/project/date range, with a By-week table (weeks starting Monday, "Week of d M Y" rows)
    and a By-month table side by side, each summing hours and amounts with totals; overall totals across
    projects; approved entries only. Covered by ReportTest::test_project_timesheet_sums_hours_by_week_and_month.

- [ ] SKIP - need to handle client who "reverse invoice" - as in I fill their timesheet system and they send me a payment that is itemised like my time-based invoice timesheet

- [x] allow abn/acn/tfn to be display formatted in the way they normally are - also allow entry with the usual spaces
  - (Sep 2026) Done. New `App\Support\AuNumbers` (ABN 2-3-3-3, ACN 3-3-3, TFN 3-3-3 / 3-5 for 8 digits;
    unexpected shapes pass through untouched) + `App\Rules\AuNumber` validation that accepts spaced entry.
    Client, Supplier, CompanyProfile, CompanyShareholder and payroll Employee models normalise ABN/ACN/TFN
    to bare digits on save (mutators) and expose formatted_abn/acn/tfn accessors; displays updated on the
    client/supplier index+show, shareholder register/show, invoice PDF, company-profile form and the company
    tax report screen/PDF (CSV exports keep bare digits). Covered by tests/Unit/AuNumbersTest.php and
    tests/Feature/AuNumberFormattingTest.php.

- [ ] DO NOT EXECUTE THIS ITEM - no need to enter both project and po project should have po

- [x] When Credit note is issued against an invoice - the invoice should no longer be marked overdue - also if invoice is cancelled then amount due should be zero dollars
  - (Sep 2026) Done. Issuing a credit note against an invoice now un-marks it: the CreditNote
    created-hook calls invoice->unmarkOverdue() (overdue → sent/partially_paid), is_overdue returns
    false, the overdue scope excludes it and invoices:mark-overdue skips it while any non-void
    credit note stands (voiding the note returns the invoice to normal dunning); the payment-status
    refresh also refuses to re-flag overdue when a credit note is active. Cancelled invoices owe
    nothing: amount_due returns 0 regardless of total/allocations. Covered by two new
    InvoiceAdvancedTest tests.

- [x] Add "select all" for create invoice from time entries against time entries

- [x] refactor to ensure all users are created linked with an entity
  - (Sep 2026) Done. The admin user form requires an Entity (select prefilled with the creator's
    entity, enforced by exists:ifrs_entities,id on store+update) and the users index shows the
    entity column; self-registration links the new user to the instance's entity (fresh installs
    with no entity yet skip the link). Covered by the updated RoleMiddlewareTest (creates assert
    the linkage; a new test refuses creation without an entity).

- [x] setting to prune transactions in closed years after x years (default 7 years)
  - (Sep 2026) Done. `ledger:prune` (scheduled yearly 1 Jan, or by hand; --dry-run, --years overrides)
    removes the double-entry trail (ifrs transactions/line items/ledgers/assignments) of CLOSED
    financial years that ended before today−N years — after writing an opening-balance snapshot at
    the prune boundary (reference PRUNE-{year}-OB, or reusing a close-generated set standing there),
    so OpeningBalances::balanceAt() returns identical figures for every date after the boundary;
    open years are never pruned and business documents (invoices/bills/payments) are kept as the
    GST/audit record. N = entity_settings.retention_years, managed on the Administration page
    (blank = default 7). Covered by tests/Feature/LedgerPruneTest.php.

- [ ] allow bill and invoice adjustment items that might be negative. allow adjustments to subtotal and gst separately.

- [ ] Balance Sheet report

- [ ] DO NOT EXECUTE THIS ITEM - Make things modular using nwidart

- [ ] Add "Admin" section to profile dropdown and move rarely executed amd setup items from the sidebar to here

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

- [x] year end close - if there is not another admin/accountant - then pass the approval request to the user that raised it

- [x] Company details - add svg or png logo - failed as it errors with "Name field required" on logo upload validation. There is an existing name

- [x] update tax report: review .zcode/ato-company-tax/company-tax.md. create plan including branch. Only expect a single manual opening balance entry for a company (migration into this system). However need an admin process to "close" a financial year  (which should potentially move some ledger balances  and create opening balances for the subsequent financial year)  - so if I have entered an opening balance debit for current assets and a matching credit to equity then the correct output should show - (Sep 2026, branch feat/fy-close-opening-balances) Done. Opening sets are now superseding snapshots: the year-end close (the existing admin trial → approve → execute process) writes FY {year+1}'s opening balances from the closing position (one Balance row per balance-sheet account incl. the profit closed into Retained Earnings, dated the year end, reference FY-CLOSE-{year}-OB), so opening balances are entered by hand exactly once, at migration. Reports (trial balance, balance sheet, account statement, company tax item 8, trial close) read as-at positions via OpeningBalances::balanceAt() — the latest snapshot plus only post-snapshot ledger movement — so a migration set (debit current assets / credit equity) shows in item 8 D/E and the new supplementary equity reconciliation, and multiple sets can never double-count. The Opening Balances screen renders close-generated sets read-only (reopen the year to change); reopen() removes the generated set.

- [x] Admin should have a backup button that backs up db and stored items with the usual backup things like backup frequency, number of backups kept. - (Sep 2026) Done. `backup:create` command (MySQL via mysqldump, SQLite via VACUUM INTO snapshot, both gzipped) + a tar.gz of the public storage disk land in {BACKUP_PATH}/db and /files (default storage/app/backups), then old archives are pruned per type. Frequency (daily/weekly/monthly) and backups-kept live in backup_settings and are managed on the Backups admin page (profile dropdown), which also lists archives and runs the command on demand; scheduled daily 04:00 with the command's internal due-check honouring the frequency. Runbook documents restore.

- [x] company details - index wants resignation date - should be optional

- [x] Company details page missing company name and optional trading name

- [x] 2026_08_28_000001_create_shares_and_dividends_tables .......... 43.80ms FAIL
  
    Illuminate\Database\QueryException 
  
   SQLSTATE[23000]: Integrity constraint violation: 1451 Cannot delete or update a parent row: a foreign key constraint fails (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: erp, SQL: drop table if `dividend_declarations`)

- [x] ### 0. `createFromTimeEntries` doesn't actually create
  
  **Files:** `app/Http/Controllers/InvoiceController.php`, `routes/web.php`, missing views
  (Sep 2026) Done. Invoiced state now derives from `invoice_items.time_entry_id` via `TimeEntry::invoiceItem()` (the phantom `invoiced` column writes are gone; cancelled invoices release their entries). `/invoices/create` turns checked unbilled entries into real linked invoice lines. Dedicated screens: "New from Time Entries" (GET picker with client filter + real `storeFromTimeEntries` POST) and PO "Create Invoice" (`storeFromPurchaseOrder`) — both build items via `InvoiceItem::createFromTimeEntry()`, screen for approved/billable/uninvoiced/single-client before persisting, and the PO flow drives `used_amount` through the existing observer chain. The previously missing views (`create-from-time-entries`, `create-from-po`, `credit-notes/create-from-invoice`) all exist, plus drive-by fixes to the dashboard unbilled-time widget (phantom columns) and the `australian.invoice.terms` config key. Covered by `tests/Feature/CreateInvoiceFromTimeEntriesTest.php`.

- [x] BAS settlement: settle PAYG withheld (2210) and income tax payable (2240) with the same netting recipe the GST settlement uses - (Sep 2026, branch feat/bas-settlements) Done. Settlements gained a type (gst / payg_withholding / income_tax): the single liability accounts play both netting roles, so a debit balance (an overpayment) settles as an ATO refund. Account codes configurable via `BAS_PAYG_ACCOUNT_CODE`/`BAS_INCOME_TAX_ACCOUNT_CODE`. The settlement screen shows all three unsettled positions and a type selector; journal narrations/references carry the type.

- [x] BAS: freeze quarters at lodgement — per-quarter BAS records so a lodged quarter stops recomputing from live ledger data - (Sep 2026, branch feat/bas-settlements) Done. `bas_statements` rows snapshot a quarter's figures (G1/G10/G11/1A/1B/net) when an admin/accountant freezes it from the BAS report; buildBasStatement() prefers the frozen figures (including in the PDF/Excel exports and FY totals), so backdated postings can never rewrite a lodged BAS. Refreezing recaptures live figures, unfreezing returns the quarter to recomputation, and quarters that haven't ended can't be frozen.

- [x] display the uploaded logo in the top left if it exists when viewing the bill record

- [x] use company logo that is uploaded on pdf invoice
