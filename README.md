<p align="center">
  <img src="public/images/logo.svg" width="120" alt="Acacia logo" />
</p>

<h1 align="center">Acacia</h1>

<p align="center">
  Open-source, cash-basis accounting for Australian professional services.<br/>
  Double-entry ledger · Time tracking · Invoicing · Wise reconciliation · BAS-ready GST.
</p>

<p align="center">
  <a href="https://github.com/baradhili/acacia/actions/workflows/tests.yml"><img alt="Tests" src="https://github.com/baradhili/acacia/actions/workflows/tests.yml/badge.svg"></a>
  <a href="https://packagist.org/packages/baradhili/acacia"><img alt="PHP Version" src="https://img.shields.io/packagist/php-v/baradhili/acacia"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/github/license/baradhili/acacia"></a>
  <a href="https://laravel.com"><img alt="Laravel" src="https://img.shields.io/badge/Laravel-13-red"></a>
  <a href="CONTRIBUTING.md"><img alt="PRs welcome" src="https://img.shields.io/badge/PRs-welcome-brightgreen"></a>
  <a href="https://github.com/baradhili/acacia/releases"><img alt="Latest release" src="https://img.shields.io/github/v/release/baradhili/acacia"></a>
</p>

---

## Table of Contents

1. [About](#about)
2. [Why Acacia?](#why-acacia)
3. [Features](#features)
4. [Technology Stack](#technology-stack)
5. [Requirements](#requirements)
6. [Installation](#installation)
7. [Configuration](#configuration)
8. [Usage Overview](#usage-overview)
9. [Architecture Notes](#architecture-notes)
10. [Testing](#testing)
11. [Roadmap](#roadmap)
12. [Contributing](#contributing)
13. [Code of Conduct](#code-of-conduct)
14. [Security](#security)
15. [License](#license)
16. [Acknowledgements](#acknowledgements)
17. [Changelog](#changelog)

---

## About

**Acacia** is a Laravel-based accounting platform built specifically for **Australian small professional-services firms** — consultants, engineers, lawyers, designers, IT services, and similar.

It replaces spreadsheets and disconnected tools with a single, auditable system that handles the full workflow: **time tracking → purchase order → invoice → payment → reconciliation → reporting**.

**Accounting model:** Cash basis — revenue is recognised when cash is received and expenses when cash is paid. Although IFRS (accrual) double-entry is used underneath, recognition timing is enforced at the application layer.

> ⚠️ **Status:** Acacia is pre-1.0 software under active development. The feature set is largely complete, but breaking changes may occur before v1.0. See the [Roadmap](#roadmap).

---

## Why Acacia?

Most open-source accounting systems are either:

- **Generic and accrual-based** (e.g. Akaunting, Frappe), requiring customisation for Australian GST/BAS and cash-basis recognition.
- **Closed-source SaaS** (e.g. Xero, MYOB, QuickBooks), with no ownership of your data and recurring fees.

Acacia occupies the middle ground:

| What | How |
| --- | --- |
| **Australian-first** | GST 10%, GST-free and input-taxed codes; July–June financial year; BAS-ready reports; ATO Company Tax Return mapping. |
| **Cash basis, properly enforced** | Revenue posts on payment receipt — not on invoice issue. Expenses post on payment — not on bill entry. |
| **Double-entry underneath** | Powered by [Eloquent IFRS v5](https://github.com/ekmungai/eloquent-ifrs), so the books are always audit-grade. |
| **Built for services** | First-class time tracking, project profitability, internal POs/budgets, billable hours → invoice conversion. |
| **Owns your data** | Self-host, no vendor lock-in. MIT licensed. |
| **Modern Laravel stack** | Laravel 13, Breeze, Spatie permissions, Blade + Tailwind + Alpine. |

---

## Features

### Accounting & Ledger

- Double-entry ledger via Eloquent IFRS v5
- Australian Chart of Accounts (seeded)
- GST 10% on sales/purchases with GST-free and input-taxed codes
- Cash-basis revenue/expense recognition (enforced at the application layer)
- Multi-currency (AUD base)
- July–June financial year with period locking
- Journal entries and manual adjustments (accountant role only)

### Contacts (Clients, Suppliers)

- Unified contact management inspired by Akaunting / Invoice Ninja
- Each contact auto-created as an IFRS `Entity` (AUD currency)
- Contact types: customer, supplier, vendor
- Billing, shipping, and contact details
- Customer portal login (optional)
- Per-contact notes, custom fields, document attachments
- Per-contact transaction history and AR/AP aging

### Items, Products & Services

- Service/product catalog with sell and purchase rates
- Default tax rates per item
- Unit of measure (hour, day, fixed)
- Optional inventory tracking (off by default for services)

### Time Tracking

- Log hours against client, project, and/or PO
- Billable vs non-billable toggle
- Start/end timestamps + duration + rate + total
- Staff timesheet views (day / week / month)
- Approval workflow for submitted time
- Convert approved time entries into invoice line items

### Projects

- Group time entries, invoices, and POs
- Project budget (hours and/or dollar)
- Project profitability (revenue − cost)
- Assigned staff with project-specific charge-out rates

### Purchase Orders (Internal Budgets)

- Create POs for a client/project with a budgeted amount
- Allocate time entries against the PO
- Real-time used vs remaining budget
- Status: `draft → open → partially_used → completed → cancelled`
- One-click convert PO → invoice, or invoice partially
- Email alerts at 80% and 100% utilisation

### Invoices

- Manual creation or generation from time entries / PO
- Line items: description, qty, unit price, tax, discount
- Australian-format invoice numbering (e.g. `INV-2025-0001`)
- Statuses: `draft → sent → viewed → partially_paid → paid → overdue → cancelled`
- Automatic overdue detection (cron)
- Recurring invoices (daily / weekly / monthly / yearly)
- Email invoices to clients with PDF attachment
- Customisable Blade templates
- Customer portal "pay now" / "view" links
- Credit notes (full and partial)
- Quotes/estimates → convert to invoices

### Payments & Receipts

- Record incoming cash receipts against a client
- Allocate payment to one or more invoices (FIFO default, manual override)
- Revenue recognised on receipt date (cash basis)
- Partial payments supported
- Payment methods: bank transfer (Wise), credit card (optional), cash, cheque
- Refund handling via credit notes

### Bills & Expenses

- Record supplier bills and expense payments
- Categorise expenses (travel, software, subcontractors, etc.) — expense, capital-purchase and prepaid-asset categories
- Per-line GST treatment (inclusive, ex-GST add-on, GST-free)
- Prepaid subscriptions/licences: per-line service period; amortised monthly by scheduled `prepayments:amortise` runner
- Domain names: capitalise initial purchase, expense renewals
- Attach receipts
- Pay bill → IFRS cash payment entry on date paid

### Bank Reconciliation — Wise

- **CSV Import:** Upload Wise statement, auto-match to IFRS transactions
- **API Sync:** Scheduled daily pull via `reconcile:wise` command
- Match logic: reference → amount + date (tolerance window)
- Auto-create missing cash receipts (credit) or purchases (debit)
- One-click link to existing IFRS transaction
- "Ignore" action for non-business transactions
- Reconciliation dashboard with matched/unmatched counts
- Multi-currency Wise balances supported

### Reporting

Built on IFRS reports, extended with project/PO reports:

- Account Statement · Account Schedule · Aging Schedule (AR/AP)
- Trial Balance · Income Statement (P&L) · Balance Sheet · Cash Flow Statement
- **GST/BAS Report** (Australian Tax Office format-ready)
- **ATO Company Tax Report** (NAT 0656 label mapping, cash-basis/GST-exclusive, V01–V13 validation checks — see `docs/ATO_tax_report_spec.md`)
- Company Details (ABN/TFN/ACN, registered address, directors and shareholder registry)
- Prepayment Amortisation Schedule
- Income by Customer · Expenses by Category
- Project Profitability · PO Budget vs Actual
- Time by Client / Staff / Project · Tax Summary by period
- Export to PDF / Excel / CSV

### Document Management

- Attach PDFs / images / docs to any transaction
- Stored under `storage/app/public/uploads/{year}/{month}/`
- Linked and versioned in DB
- Audit trail of who uploaded and when

### Roles & Permissions (Spatie)

- **Admin** — full access including config and user management
- **Accountant** — ledger, reports, reconciliation, journal entries
- **Staff** — own time entries, invoices they create, assigned projects
- **Client (portal)** — view own invoices, pay, download PDFs

### Automation

- Daily cron: Wise API sync, overdue invoice detection, recurring invoices, prepayment amortisation
- Email notifications: invoice sent/viewed, payment received, PO budget threshold, overdue reminders
- Scheduled monthly client statements
- Optional webhooks for invoice paid / payment received

### Dashboard

- Cash flow (last 30 days)
- AR aging summary
- Recent invoices and payments
- Outstanding PO budgets
- Unbilled time entries
- Wise balance + unreconciled count
- Monthly P&L trend

### Audit & Compliance

- Audit log of all material transactions (created / edited / deleted)
- IFRS postings are immutable (must be reversed, not edited)
- Period locking (close prior FY)
- BAS-ready GST report

---

## Technology Stack

| Layer | Technology |
| --- | --- |
| Framework | Laravel 13 (PHP 8.2+) |
| Accounting core | Eloquent IFRS v5 (`ekmungai/eloquent-ifrs`) |
| Database | MySQL 8 / PostgreSQL 15+ |
| Auth | Laravel Breeze (Blade + Tailwind) |
| Permissions | Spatie Laravel Permission v6 |
| HTTP client | GuzzleHTTP 7 |
| Frontend | Blade + Tailwind CSS + Alpine.js |
| PDF | `barryvdh/laravel-dompdf` |
| Excel | `maatwebsite/excel` |
| Charts | Chart.js via Blade |
| Queue | Laravel Queue (database / Redis) |
| Scheduler | Laravel Scheduler (cron) |

---

## Requirements

- PHP **8.2+** with `bcmath`, `ctype`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`
- Composer 2.x
- MySQL 8+ **or** PostgreSQL 15+
- A [Wise Business Account](https://wise.com/business) (optional, for bank reconciliation)
- Cron access (for scheduled tasks)

---

## Installation

```bash
# 1. Clone the repository
git clone https://github.com/baradhili/acacia.git
cd acacia

# 2. Install PHP dependencies
composer install

# 3. Environment setup
cp .env.example .env
php artisan key:generate

# 4. Configure your database and Wise credentials in .env (see Configuration)

# 5. Run migrations and seeders
php artisan migrate --seed

# 6. Initialise the IFRS reporting period & currencies
php artisan ifrs:setup

# 7. Create the public storage symlink
php artisan storage:link

# 8. Start the development server
php artisan serve
```

Then visit `http://localhost:8000` and log in with the seeded admin account (see output of the seeder, or check `database/seeders/DemoCompanySeeder.php`).

### Quick start with Laravel Sail (Docker)

```bash
composer require laravel/sail --dev
php artisan sail:install
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate --seed
```

---

## Configuration

### Required `.env` keys

```dotenv
APP_TIMEZONE=Australia/Sydney
APP_LOCALE=en-AU

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=acacia
DB_USERNAME=root
DB_PASSWORD=

# IFRS / Accounting
IFRS_BASE_CURRENCY=AUD
IFRS_REPORTING_PERIOD_START=2025-07-01

# Wise (optional — leave blank to disable bank reconciliation)
WISE_API_TOKEN=
WISE_PROFILE_ID=
WISE_ACCOUNT_ID=
WISE_WEBHOOK_SECRET=

# Mail (for invoice/statement emails)
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS="accounts@yourfirm.com.au"
MAIL_FROM_NAME="${APP_NAME}"
```

### Cron (production)

Add the following to your server's crontab:

```cron
* * * * * cd /path/to/acacia && php artisan schedule:run >> /dev/null 2>&1
```

### Scheduled tasks

| Command | Frequency | Purpose |
| --- | --- | --- |
| `reconcile:wise` | Daily | Pull Wise transactions and auto-match |
| `invoices:mark-overdue` | Daily | Mark past-due invoices as overdue |
| `invoices:recurring` | Daily | Generate recurring invoices due today |
| `prepayments:amortise` | Monthly | Post monthly amortisation of prepaid subscriptions |
| `statements:send` | Monthly | Email client statements |

---

## Usage Overview

### Clients / Suppliers

- CRUD UI; each becomes an IFRS `Entity` with AUD currency
- Toggle contact type (customer / supplier / vendor)
- View aging, transactions, attached documents

### Time Tracking

- Staff log hours against client / project / PO
- Submit weekly timesheet for approval
- Approved entries are eligible for invoicing

### Purchase Orders

- Create PO with budget for a client/project
- Allocate time entries; system tracks used vs remaining
- Notify at 80% / 100% consumed
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
- One-click create missing receipt / purchase

### Reporting

- IFRS reports accessible from `/reports`
- Filters: date range, currency, account, contact
- Export to PDF / Excel / CSV

---

## Architecture Notes

### Key Models

- `Contact` (morphs to IFRS `Entity`) — implemented as `Client`, `Supplier`, `Vendor`
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
- Tax code `GST_FREE` (0%) for exports / input-taxed
- Tax code `INPUT` (10%) on purchases
- BAS report aggregates `GST_COLLECTED − GST_PAID`

See `docs/architecture.md` for deeper detail.

---

## Testing

```bash
# Full suite
php artisan test

# Feature tests only
php artisan test --testsuite=Feature

# Single test class
php artisan test --filter=WiseReconciliationTest
```

### Coverage targets

| Area | Target |
| --- | --- |
| TODO | ... |

Please ensure all tests pass before opening a pull request. New features should ship with corresponding tests.

---

## Roadmap

TODO

## Contributing

Contributions are welcome and appreciated! 🇦🇺

Please read [`CONTRIBUTING.md`](CONTRIBUTING.md) for details on the development workflow, coding standards, and pull request process.

### Quick guidelines

1. **Fork** the repository and create a feature branch: `git checkout -b feature/my-feature`
2. **Follow the existing code style** — Laravel Pint is configured: `./vendor/bin/pint`
3. **Write tests** for any new feature or bug fix
4. **Ensure tests pass**: `php artisan test`
5. **Update documentation** (README, `docs/`) where relevant
6. **Open a pull request** with a clear description of what changed and why

### Good first issues

Look for issues labelled [`good first issue`](https://github.com/baradhili/acacia/labels/good%20first%20issue) and [`help wanted`](https://github.com/baradhili/acacia/labels/help%20wanted) — these are scoped to be approachable for new contributors.

### Areas we'd love help with

- End-to-end test suite (Cypress / Laravel Dusk)
- Performance benchmarking at scale (10k+ invoices, 100k+ time entries)
- Security review (mass-assignment, IDOR, XSS)
- Additional bank integrations beyond Wise (ANZ, CBA, NAB, Westpac API feeds)
- Translations (`resources/lang/`)
- Invoice template themes

---

## Code of Conduct

This project follows the [Contributor Covenant 2.1](https://www.contributor-covenant.org/version/2/1/code_of_conduct/) Code of Conduct. By participating, you are expected to uphold this code. Please report unacceptable behaviour to `info@ticm.com`.

---

## Security

If you discover a security vulnerability, **please do not open a public issue**. Instead, email `info@ticm.com` with a description and, where possible, a proof of concept. We will acknowledge receipt within 48 hours and aim to provide a fix or mitigation within 14 days.

See [`SECURITY.md`](SECURITY.md) for the full policy.

---

## License

Acacia is open-source software licensed under the **[MIT License](LICENSE)**.

You are free to use, modify, and distribute this software, including for commercial purposes, provided the original copyright and licence notice are included.

> **Note:** Acacia integrates with [Eloquent IFRS](https://github.com/ekmungai/eloquent-ifrs), which is licensed under the MIT licence. Please review its licence if you redistribute this project.

---

## Acknowledgements

Acacia stands on the shoulders of giants. The project draws inspiration and reference from:

- **[Eloquent IFRS](https://github.com/ekmungai/eloquent-ifrs)** by Elkman Mungai — the double-entry accounting core
- **[Laravel](https://laravel.com)** and the wider Laravel ecosystem (Breeze, Pint, Sail, Queue)
- **[Akaunting](https://akaunting.com)** and **[Invoice Ninja](https://invoiceninja.com)** — feature inspiration for contact management, invoicing, and portal UX
- **[Spatie](https://spatie.be)** for the role/permission package
- The **[Australian Taxation Office](https://www.ato.gov.au)** for public guidance on GST, BAS, and Company Tax Return formats

Made with care for the Australian small-business community.

---

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md) for the full history. Versions follow [Semantic Versioning](https://semver.org/).

| Version | Notes |
| --- | --- |
| 0.1.0 | Functioning accounting system |

---

<p align="center">
  <sub>Built for Australian professional services.</sub><br/>
  <sub>Star ⭐ the repo if Acacia is useful to your firm.</sub>
</p>
