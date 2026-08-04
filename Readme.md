# Professional Services Accounting System

A Laravel 13‑based accounting solution tailored for an Australian small professional services company.  
Uses **cash‑based accounting** (not accrual) and leverages the **Eloquent IFRS v5** package for a robust double‑entry ledger.  
Includes full time tracking, invoicing with status tracking, payment recording, purchase orders, and document attachment – all integrated with a **Wise business account** for seamless reconciliation.

---

## Overview

This system is designed to replace manual spreadsheets and disconnected tools. It provides a single source of truth for:

- Client, supplier, and vendor management
- Time tracking against projects and purchase orders (POs)
- Invoice generation and status tracking (draft, sent, partially paid, paid, overdue, cancelled)
- Payment recording and allocation to invoices (cash‑basis revenue recognition)
- Document attachments to any transaction (invoices, payments, POs)
- Purchase orders to budget and allocate time
- Bank reconciliation with your Wise account (CSV or API)
- Australian GST (10%) handling and financial year (July–June)

All accounting entries are processed through the **Eloquent IFRS** package, ensuring a professional, auditable ledger.

---

## Key Features

- **Cash‑based accounting** – revenue recognised when cash is received, expenses when cash is paid.
- **Australian compliance** – GST configured at 10%, financial year set to July–June.
- **Time tracking** – log hours against clients, projects, and POs.
- **Purchase Orders** – track budgeted vs. allocated amounts; generate invoices from time against POs.
- **Invoices** – create client invoices with line items from time entries; maintain full status history.
- **Payment recording** – record incoming payments and automatically allocate them to outstanding invoices (FIFO or manual assignment).
- **Document management** – attach files (PDF, images, etc.) to any transaction for audit trail.
- **Wise reconciliation** – import Wise transactions via CSV or API, match them to IFRS transactions, and auto‑create missing receipts/purchases.
- **Role‑based access** – admin, accountant, and staff roles with granular permissions.
- **Reporting** – account statements, aging schedules, trial balance, income statement, balance sheet.

---

## Technology Stack

- **Framework**: Laravel 13 (PHP 8.2+)
- **Accounting Core**: Eloquent IFRS v5 (ekmungai/eloquent-ifrs)
- **Database**: MySQL / PostgreSQL (recommended) or SQLite
- **Authentication**: Laravel Breeze (Blade stack)
- **Permissions**: Spatie Laravel Permission
- **API Client**: GuzzleHTTP (for Wise API integration)
- **Frontend**: Blade templates with Tailwind CSS (included with Breeze)

---

## Dependencies

Below are the main packages required. They will be installed via Composer.

- `php`: ^8.2
- `laravel/framework`: ^13.0
- `ekmungai/eloquent-ifrs`: ^5.0
- `spatie/laravel-permission`: ^6.0
- `guzzlehttp/guzzle`: ^7.0
- `laravel/breeze`: ^2.0 (development)
- `doctrine/dbal`: ^3.0 (for schema modifications)

Optional development dependencies:

- `nunomaduro/collision`: ^8.0
- `laravel/pint`: ^1.0
- `laravel/sail`: ^1.0 (for Docker)

---

## References

https://github.com/ekmungai/eloquent-ifrs/raw/refs/heads/master/README.md



## Usage Overview

### Clients / Suppliers

- Add new clients/suppliers via dedicated CRUD interfaces.

- Each is automatically an IFRS `Entity` with AUD currency.

### Time Tracking

- Staff log hours against a client and optionally a project or purchase order.

- Each entry records start/end, hours, rate, and total amount.

### Purchase Orders

- Create POs for a client with a budgeted amount.

- Allocate time entries against the PO – the system tracks used vs. remaining budget.

- When PO is fully utilised, it can be marked completed.

### Invoices

- Generate an invoice manually or from selected time entries / a PO.

- The invoice includes line items with descriptions, quantities, and amounts (including GST).

- Status is automatically updated as payments are received (partial/full).

### Payments

- Record incoming payments (cash receipts) against a client.

- Link the payment to one or more invoices via assignments.

- On receipt, revenue is recognised immediately (cash basis).

### Document Attachments

- Upload files (PDF, images, Word) to any transaction: invoices, payments, purchase orders.

- Files are stored in `storage/app/public/uploads/` and linked in the database.

### Bank Reconciliation (Wise)

- **CSV Import**: Upload a Wise statement CSV; the system matches transactions to existing IFRS transactions by reference or amount/date.

- **API Integration**: Optionally connect to Wise API to fetch transactions automatically (daily cron).

- Unmatched Wise transactions can be automatically converted into IFRS cash receipts or purchases, or flagged for manual review.

### Reporting

- Use
   IFRS’s built‑in reports: Account Statement, Account Schedule, Aging 
  Schedule, Trial Balance, Income Statement, Balance Sheet.

- Filter by date ranges and currency.

---

## Reconciliation with Wise

Two approaches:

1. **Manual CSV Upload**
   
   - Export transactions from Wise (CSV).
   
   - Use the import form to upload the file.
   
   - The system processes each row and attempts to match against `ifrs_transactions` by `reference` or by amount+date within a tolerance.
   
   - Matched rows are linked and marked reconciled.
   
   - Unmatched rows are presented for manual action (create missing transaction or ignore).

2. **Automated API Sync**
   
   - Configure your Wise API token and account ID in `.env`.
   
   - A scheduled command (`php artisan reconcile:wise`) runs daily to fetch new transactions from Wise.
   
   - New transactions are stored in `wise_transactions` and automatically reconciled using the same matching logic.
   
   - Any unmatched transaction will create a default cash receipt/purchase if the amount is a credit/debit respectively.

All reconciled entries update the bank account balance in IFRS, ensuring your ledger matches Wise at all times.

---

## Roadmap

| Phase       | Deliverables                                                                                  |
| ----------- | --------------------------------------------------------------------------------------------- |
| **Phase 1** | Installation, IFRS configuration, chart of accounts, GST, basic seeding. (Complete)           |
| **Phase 2** | User auth with roles, client/supplier/vendor CRUD, Wise reconciliation foundation. (Complete) |
| **Phase 3** | Time tracking, purchase orders, allocation logic.                                             |
| **Phase 4** | Invoice generation, status tracking, payment recording and assignment.                        |
| **Phase 5** | Document attachments and full reporting suite.                                                |
| **Phase 6** | Advanced reconciliation (auto‑create missing transactions) and dashboard widgets.             |
