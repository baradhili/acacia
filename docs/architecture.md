# Architecture Deep Dive

## Core Layers

### 1. Presentation Layer
- **Blade Templates**: Server-side rendering for admin panels and client portals.
- **Livewire/Alpine.js**: Reactive components for forms and data tables.

### 2. Application Layer (Controllers)
- **InvoiceController**: Handles generation, PDF export, and emailing.
- **ReconciliationController**: Coordinates Wise API/CSV imports and matching.
- **TimeTrackingController**: Manages start/stop and approval workflows.

### 3. Domain Layer (Models & IFRS)
- **Eloquent IFRS**: Manages all double-entry journal entries, accounts, and tax calculations.
- **Custom Models**: Extend IFRS models to add ERP-specific relations (e.g., Project to Invoice).

### 4. Infrastructure Layer
- **Wise API Client**: Custom wrapper for Wise Business API.
- **Queue Workers**: Handles PDF generation and bulk emailing in the background.

## IFRS Integration
The system uses a modified version of the `Eloquent IFRS` package. Key additions include:
- Mandatory `contact_id` on all transactions to enforce audit trails.
- Custom tax rates for Australian GST.
- Extended reporting period logic to handle July-June financial years.

## Database Schema Highlights
- **Contacts**: Acts as a polymorphic parent for Clients, Suppliers, and Employees.
- **Projects**: Contains metadata, budget, and status.
- **TimeEntries**: Stores hours, rate, and references to Project and optionally PO.
- **Ledger**: All financial entries are stored in the IFRS tables (`journal_entries`, `accounts`, `transactions`).
