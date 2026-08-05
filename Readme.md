# Professional Services Accounting System — Enhanced README

Below is a significantly expanded README. The roadmap has been broken into granular, checkable tasks (`- [ ]`) so each can be tracked as "todo" / "done" in GitHub Issues, Projects, or any kanban board. Feature scope has been broadened using the public feature sets of **Akaunting** and **Invoice Ninja** as references, while staying true to the project's core: **cash-based accounting**, **Australian GST**, **Eloquent IFRS v5**, and **Wise reconciliation**.

---

## 1. Overview

A **Laravel 13** accounting platform tailored for an Australian small professional services company (consulting, engineering, legal, design, IT services, etc.). It replaces spreadsheets and disconnected tools with a single, auditable system.

**Accounting model:** Cash basis — revenue is recognised when cash is received and expenses when cash is paid. Although IFRS (accrual) double-entry is used underneath, recognition timing is enforced at the application layer.

**Core differentiators:**

- Double-entry ledger powered by **Eloquent IFRS v5**
- Native **Wise Business Account** reconciliation (CSV + API)
- Full **time tracking → PO → invoice → payment** workflow
- **Australian GST (10%)** and **July–June financial year** configured by default
- Role-based access for **admins, accountants, and staff**

---

## 2. Key Features

### 2.1 Accounting & Ledger

- Double-entry ledger via Eloquent IFRS v5
- Australian Chart of Accounts (seeded)
- GST 10% configured on sales/purchases with GST-free and input-taxed codes
- Cash-basis revenue/expense recognition policy
- Multi-currency support (AUD base)
- Financial year July–June with period locking
- Journal entries and manual adjustments (accountant role only)

### 2.2 Contacts (Clients, Suppliers, Vendors)

- Unified contact management inspired by Akaunting/Invoice Ninja
- Each contact auto-created as an IFRS `Entity` (AUD currency)
- Contact types: customer, supplier, vendor (multiple per contact)
- Billing, shipping, and contact details
- Customer portal login (optional, Invoice Ninja-style)
- Per-contact notes, custom fields, document attachments
- View per-contact transaction history and AR/AP aging

### 2.3 Items, Products & Services

- Service/product catalog with sell and purchase rates
- Default tax rates per item
- Unit of measure (hour, day, fixed)
- Inventory tracking (optional, off by default for services)

### 2.4 Time Tracking

- Log hours against client, project, and/or PO
- Billable vs non-billable toggle
- Start/end timestamps + duration + rate + total
- Staff timesheet views (day/week/month)
- Approval workflow for submitted time
- Convert approved time entries into invoice line items

### 2.5 Projects

- Projects group time entries, invoices, and POs
- Project budget (hours and/or dollar)
- Project profitability (revenue − cost)
- Assigned staff and rates

### 2.6 Purchase Orders (Internal Budgets)

- Create POs for a client/project with a budgeted amount
- Allocate time entries against the PO
- Track used vs remaining budget (real-time)
- Status: draft, open, partially used, completed, cancelled
- Convert PO → invoice (one click) or invoice partially

### 2.7 Invoices

- Manual creation or generation from time entries / PO
- Line items: description, qty, unit price, tax, discount
- Australian-format invoice numbering (e.g. INV-2025-0001)
- Invoice statuses: `draft`, `sent`, `viewed`, `partially_paid`, `paid`, `overdue`, `cancelled`
- Automatic overdue detection (cron)
- Recurring invoices (optional)
- Email invoices to clients with PDF attachment
- Customisable invoice templates (Blade)
- Customer portal "pay now" / "view" links
- Credit notes
- Quotes/estimates that convert to invoices (optional)

### 2.8 Payments & Receipts

- Record incoming cash receipts against a client
- Allocate a payment to one or more invoices (FIFO or manual)
- On receipt → revenue is recognised (cash basis)
- Partial payments supported
- Payment methods: bank transfer (Wise), credit card (optional), cash, cheque
- Refund handling (credit notes)

### 2.9 Bills & Expenses (Suppliers)

- Record supplier bills and expense payments
- Categorise expenses (travel, software, subcontractors, etc.)
- Attach receipts
- Pay bill → IFRS cash payment entry on date paid (cash basis)

### 2.10 Bank Reconciliation — Wise

- **CSV Import**: Upload Wise statement, auto-match to IFRS transactions
- **API Sync**: Scheduled daily pull via `reconcile:wise` command
- Match logic: reference → amount+date (tolerance window)
- Auto-create missing cash receipts (credit) or purchases (debit)
- Reconciliation dashboard with matched/unmatched counts
- Multi-currency Wise balances supported

### 2.11 Reporting

Built on IFRS reports, extended with project/PO reports:

- Account Statement
- Account Schedule
- Aging Schedule (AR/AP)
- Trial Balance
- Income Statement (Profit & Loss)
- Balance Sheet
- Cash Flow Statement
- GST/BAS Report (Australian Tax Office format-ready)
- Income by Customer
- Expenses by Category
- Project Profitability
- PO Budget vs Actual
- Time by Client / Staff / Project
- Tax Summary by period

### 2.12 Document Management

- Attach PDFs/images/docs to any transaction
- Stored under `storage/app/public/uploads/`
- Linked and versioned in DB
- Audit trail of who uploaded and when

### 2.13 Roles & Permissions (Spatie)

- **Admin**: full access including config and user management
- **Accountant**: ledger, reports, reconciliation, journal entries
- **Staff**: own time entries, invoices they create, assigned projects
- **Client (portal)**: view own invoices, pay, download PDFs

### 2.14 Automation

- Daily cron: Wise API sync, overdue invoice detection
- Email notifications: invoice sent, payment received, PO budget threshold
- Scheduled statements to clients (monthly)
- Webhooks (optional) for invoice paid / payment received

### 2.15 Dashboard Widgets

- Cash flow (last 30 days)
- AR aging summary
- Recent invoices and payments
- Outstanding PO budgets
- Unbilled time entries
- Wise balance + unreconciled transactions
- Monthly profit/loss trend

### 2.16 Audit & Compliance

- Audit log of all material transactions (created/edited/deleted)
- IFRS postings are immutable (cannot be edited — must be reversed)
- Period locking
- BAS-ready GST report

---

## 3. Technology Stack

| Layer                   | Technology                                  |
| ----------------------- | ------------------------------------------- |
| Framework               | Laravel 13 (PHP 8.2+)                       |
| Accounting core         | Eloquent IFRS v5 (`ekmungai/eloquent-ifrs`) |
| Database                | MySQL 8 / PostgreSQL 15+                    |
| Auth                    | Laravel Breeze (Blade + Tailwind)           |
| Permissions             | Spatie Laravel Permission v6                |
| HTTP client             | GuzzleHTTP 7                                |
| Frontend                | Blade + Tailwind CSS + Alpine.js            |
| PDF                     | `dompdf` or `barryvdh/laravel-dompdf`       |
| Excel (imports/exports) | `maatwebsite/excel`                         |
| Charts                  | `consoletvs/charts` or Chart.js via Blade   |
| Queue                   | Laravel Queue (database/Redis)              |
| Scheduler               | Laravel Scheduler (cron)                    |

---

## 4. Dependencies

```
php: ^8.2
laravel/framework: ^13.0
ekmungai/eloquent-ifrs: ^5.0
spatie/laravel-permission: ^6.0
guzzlehttp/guzzle: ^7.0
laravel/breeze: ^2.0
doctrine/dbal: ^3.0
barryvdh/laravel-dompdf: ^3.0
maatwebsite/excel: ^3.1
```

Dev:

```
nunomaduro/collision: ^8.0
laravel/pint: ^1.0
laravel/sail: ^1.0
phpunit/phpunit: ^11.0
fakerphp/faker: ^1.23
```

---

## 5. Installation

```bash
git clone <repo-url> psa
cd psa
composer install
cp .env.example .env
php artisan key:generate
# Configure DB and Wise credentials in .env
php artisan migrate --seed
php artisan ifrs:setup        # Initialise IFRS reporting period & currencies
php artisan storage:link
php artisan serve
```

### Required `.env` keys

```
APP_TIMEZONE=Australia/Sydney
APP_LOCALE=en-AU
IFRS_BASE_CURRENCY=AUD
IFRS_REPORTING_PERIOD_START=YYYY-07-01
WISE_API_TOKEN=
WISE_PROFILE_ID=
WISE_ACCOUNT_ID=
WISE_WEBHOOK_SECRET=
```

### Cron (production)

```
* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
```

---

## 6. Usage Overview

### Clients / Suppliers

- CRUD UI; each becomes an IFRS `Entity` with AUD currency
- Toggle contact type (customer/supplier/vendor)
- View aging, transactions, attached documents

### Time Tracking

- Staff log hours against client/project/PO
- Submit weekly timesheet for approval
- Approved entries are eligible for invoicing

### Purchase Orders

- Create PO with budget for a client/project
- Allocate time entries; system tracks used vs remaining
- Notify when 80% / 100% consumed
- Mark PO complete when fully utilised

### Invoices

- Generate from selected time entries / PO or manually
- Auto-calculated line totals + GST
- Email PDF to client with payment link
- Status updates automatically based on payments

### Payments

- Record receipts against a client
- Allocate to invoices (FIFO default, manual override)
- Revenue recognised on receipt date (cash basis)

### Bills / Expenses

- Record supplier bill
- Pay bill → cash payment entry on payment date
- Attach receipt, categorise

### Bank Reconciliation (Wise)

- **CSV**: upload Wise export; auto-match
- **API**: daily cron pulls transactions; auto-reconciles
- Unmatched items appear on dashboard for review
- One-click create missing receipt/purchase

### Reporting

- IFRS reports accessible from `/reports`
- Filters: date range, currency, account, contact
- Export to PDF / Excel

---

## 7. Architecture Notes

### Key Models

- `Contact` (morphs to IFRS `Entity`)
- `Project`
- `PurchaseOrder`
- `TimeEntry`
- `Invoice` (wraps IFRS `Invoice` / `CashSale`)
- `InvoiceItem`
- `Payment` → IFRS `CashReceipt` + assignments
- `Bill` / `Expense` → IFRS `CashPayment` / `Bill`
- `Document` (polymorphic attachments)
- `WiseTransaction`
- `ReconciliationMatch`

### Cash-Basis Enforcement

- `Invoice::issue()` does **not** post revenue
- `Payment::allocate()` posts `Dr Cash / Cr Revenue` on the payment date
- `Bill` posting deferred until `Bill::pay()` runs
- Accrual toggles kept off at the IFRS layer

### GST Handling

- Tax code `GST` (10%) on sales
- Tax code `GST_FREE` (0%) for exports/input-taxed
- Tax code `INPUT` (10%) on purchases
- BAS report aggregates `GST_COLLECTED` − `GST_PAID`

---

## 8. Testing

```bash
php artisan test
php artisan test --testsuite=Feature
php artisan test --filter=WiseReconciliationTest
```

Coverage targets:

- Models & relationships: 90%
- IFRS integration: 85%
- Wise reconciliation (CSV + API mock): 90%
- Invoice lifecycle: 95%

---

## 9. References

- Eloquent IFRS: https://github.com/ekmungai/eloquent-ifrs
- Akaunting features: https://akaunting.com/features
- Invoice Ninja features: https://invoiceninja.com/features/
- ATO BAS guidance: https://www.ato.gov.au/Business/GST/

---

## 10. Roadmap

The roadmap is broken into phases with granular, checkable tasks. Use these as GitHub Issues or Project cards. Mark `- [x]` when complete.

### Phase 1 — Foundation & Accounting Core

- [x] Scaffold Laravel 13 project with Breeze (Blade stack)
- [x] Configure `.env.example` with all required keys
- [x] Install Eloquent IFRS v5 via Composer
- [x] Run `php artisan ifrs:setup` and verify base currency = AUD
- [x] Configure reporting period start = 1 July (Australian FY)
- [x] Seed Australian Chart of Accounts (assets, liabilities, equity, income, expenses)
- [x] Configure GST tax codes: GST (10%), GST_FREE, INPUT
- [x] Set up exchange rates table for multi-currency (USD, EUR, GBP, NZD)
- [x] Install Spatie Laravel Permission and seed roles (admin, accountant, staff, client)
- [x] Install and configure Doctrine DBAL for schema introspection
- [x] Set up Laravel Pint + PHPUnit baseline config
- [x] Write smoke test: IFRS posting of a journal entry
- [x] Create database seeders for demo company (admin user + sample data)

### Phase 2 — Auth, Contacts & Wise Foundation

- [x] Implement Breeze login/registration/profile
- [x] Add email verification
- [x] Add password reset
- [ ] Implement role middleware (`role:admin`, `role:accountant`, `role:staff`)
- [x] Build navigation menu with role-based visibility
- [x] Create `Contact` model + migration (morphs to IFRS `Entity`) — implemented as Client, Supplier, Vendor models
- [x] Contact CRUD: create, list, edit, soft-delete
- [x] Contact types: customer / supplier / vendor (separate models)
- [ ] Per-contact billing & shipping addresses
- [ ] Custom fields on contacts (JSON column)
- [ ] Contact detail view: transactions, aging, attachments
- [x] Install GuzzleHTTP; create Wise API client service class
- [x] Create `wise_transactions` migration + model
- [x] Implement Wise CSV import endpoint
- [ ] Add `reconcile:wise` artisan command (daily schedule)
- [x] Build reconciliation dashboard (matched / unmatched counts)
- [ ] Implement matching logic: reference → amount+date tolerance
- [ ] Write feature tests for CSV import
- [ ] Write feature tests for API sync (mocked)

### Phase 3 — Time Tracking, Projects & Purchase Orders

- [ ] `Project` model + migration (client_id, name, budget_hours, budget_amount)
- [ ] Project CRUD UI
- [ ] Project staff assignment + per-staff rate
- [ ] `TimeEntry` model + migration (start, end, hours, rate, billable, project_id, po_id)
- [ ] Time entry create/edit UI (staff-facing)
- [ ] Weekly timesheet view (per staff)
- [ ] Monthly timesheet view (per staff)
- [ ] Time entry approval workflow (staff submit → accountant/admin approves)
- [ ] `PurchaseOrder` model + migration (client_id, project_id, budgeted_amount, used_amount, status)
- [ ] PO CRUD UI
- [ ] PO allocate-time endpoint (attach time entries to PO)
- [ ] Real-time used vs remaining budget calculation
- [ ] PO status state machine: draft → open → partially_used → completed → cancelled
- [ ] Email notification when PO hits 80% utilisation
- [ ] Email notification when PO fully utilised
- [ ] Project profitability report (revenue − staff cost)
- [ ] Time-by-client / time-by-staff / time-by-project reports
- [ ] Feature tests for time entry lifecycle
- [ ] Feature tests for PO allocation logic

### Phase 4 — Invoices, Credit Notes & Payments

- [ ] `Invoice` model + migration (wraps IFRS invoice, status, due_date, client_id)
- [ ] `InvoiceItem` model (description, qty, unit_price, tax_id, discount, total)
- [ ] Invoice CRUD UI
- [ ] Generate invoice from selected time entries
- [ ] Generate invoice from PO (partial or full)
- [ ] Australian invoice numbering (INV-YYYY-NNNN)
- [ ] Invoice status state machine: draft → sent → viewed → partially_paid → paid → overdue → cancelled
- [ ] Email invoice to client with PDF attachment
- [ ] PDF rendering via dompdf (Australian-format template)
- [ ] Customisable invoice template (Blade)
- [ ] Customer portal: view invoice (signed URL)
- [ ] Customer portal: pay invoice (Wise / bank transfer instructions)
- [ ] Recurring invoices (daily/weekly/monthly/yearly)
- [ ] Credit notes (full and partial)
- [ ] Refund workflow
- [ ] Overdue detection cron (mark `sent` → `overdue` past due_date)
- [ ] `Payment` model + migration (client_id, amount, date, method, reference)
- [ ] Payment create UI
- [ ] Allocate payment to invoices (FIFO default)
- [ ] Manual payment allocation override
- [ ] Partial payment support
- [ ] On payment → post IFRS `Dr Cash / Cr Revenue` on payment date (cash basis)
- [ ] Payment receipt email to client
- [ ] Quote/Estimate module → convert to invoice
- [ ] Feature tests for invoice lifecycle
- [ ] Feature tests for payment allocation (FIFO + manual)
- [ ] Feature tests for credit notes

### Phase 5 — Bills, Expenses, Documents & Reporting

- [ ] `Bill` model + migration (supplier_id, due_date, total, status)
- [ ] Bill CRUD UI
- [ ] `Expense` model (category, amount, date, supplier_id)
- [ ] Expense CRUD UI
- [ ] Expense categories seed (travel, software, subcontractors, etc.)
- [ ] Pay bill → IFRS `Cr Cash / Dr Expense` on payment date (cash basis)
- [ ] Attach receipt to bill/expense
- [ ] `Document` polymorphic model + migration
- [ ] Document upload UI (drag-drop)
- [ ] Document list per transaction
- [ ] Document download/delete
- [ ] Storage path: `storage/app/public/uploads/{year}/{month}/`
- [ ] Account Statement report (IFRS)
- [ ] Account Schedule report (IFRS)
- [ ] Aging Schedule (AR + AP)
- [ ] Trial Balance
- [ ] Income Statement (P&L)
- [ ] Balance Sheet
- [ ] Cash Flow Statement
- [ ] GST/BAS Report (ATO format)
- [ ] Income by Customer report
- [ ] Expenses by Category report
- [ ] Tax Summary report
- [ ] Report filters: date range, currency, account, contact
- [ ] Export reports to PDF
- [ ] Export reports to Excel
- [ ] Feature tests for reporting

### Phase 6 — Advanced Reconciliation, Automation & Dashboard

- [ ] Implement Wise API fetch endpoint (with token + profile ID)
- [ ] Auto-create missing cash receipt from unmatched Wise credit
- [ ] Auto-create missing purchase from unmatched Wise debit
- [ ] Manual override: link unmatched Wise txn to existing IFRS txn
- [ ] "Ignore" action for non-business Wise transactions
- [ ] Reconciliation history log
- [ ] Multi-currency Wise balance display
- [ ] Dashboard widget: cash flow (30-day)
- [ ] Dashboard widget: AR aging summary
- [ ] Dashboard widget: recent invoices & payments
- [ ] Dashboard widget: outstanding PO budgets
- [ ] Dashboard widget: unbilled time entries
- [ ] Dashboard widget: Wise balance + unreconciled count
- [ ] Dashboard widget: monthly P&L trend (chart)
- [ ] Email notifications: invoice viewed, payment received, overdue reminder
- [ ] Scheduled monthly client statements
- [ ] Audit log for all create/edit/delete actions on financial records
- [ ] Period locking (close prior FY)
- [ ] Webhook: invoice.paid
- [ ] Webhook: payment.received
- [ ] API tokens for external integration (Laravel Sanctum)
- [ ] OpenAPI spec for REST API
- [ ] Documentation site (Markdown → static)
- [ ] Production deployment guide (Forge/Sail/Docker)
- [ ] Backup & restore runbook
- [ ] End-to-end test suite (cypress/dusk optional)
- [ ] Performance benchmark: 10k invoices, 100k time entries
- [ ] Security review: mass-assignment, IDOR, XSS
- [ ] Release v1.0.0 tagging & changelog

---

## 11. License

Proprietary — internal use only unless otherwise agreed.

---

## 12. Changelog

See `CHANGELOG.md`. Versions follow SemVer.

| Version | Notes                                                |
| ------- | ---------------------------------------------------- |
| 0.1.0   | Phase 1 complete — IFRS core, GST, Chart of Accounts |
| 0.2.0   | Phase 2 complete — Auth, contacts, Wise CSV          |
|         |                                                      |
|         |                                                      |
|         |                                                      |
|         |                                                      |

---

**Maintainers:** Add your team here.  
**Contributing:** See `CONTRIBUTING.md`.  
**Security issues:** Email security@yourdomain — do not open public issues.
