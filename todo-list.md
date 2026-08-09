# Test Failure Todo List

Generated from `php artisan test` (PHPUnit + Behat).

## Summary

- PHPUnit Unit suite: 96 tests, 220 assertions passed (38 deprecations, no failures)
- Behat suite: 108 scenarios (31 passed, 27 failed, 50 undefined) | 603 steps (228 passed, 27 failed, 210 undefined, 138 skipped)

## Tasks

- [x] **Fix PHPUnit unit test deprecations (38)**
      PHPUnit unit suite passes but emits 38 deprecations under PHP 8.4. All 38 originate in the `ekmungai/eloquent-ifrs` vendor package (implicit nullable params). Added a PHPUnit baseline (`.phpunit.deprecations.baseline`) referenced via `<source baseline>` in `phpunit.xml` so the known vendor deprecations are acknowledged while new ones in our code still surface. Unit suite now reports `OK (96 tests, 220 assertions)`.

- [x] **Create missing `Database\Factories\InvoiceFactory`**
      Created `database/factories/InvoiceFactory.php` matching the Invoice model (fillable fields, casts, status constants). Provides `draft`/`sent`/`paid`/`overdue` state helpers and defaults `client_id`/`created_by` via nested factories. Verified the `Class "Database\Factories\InvoiceFactory" not found` failures are resolved across Documents/CreditNotes features; remaining failures there are UI element-not-found / undefined-step issues (covered by other tasks).

- [x] **Create missing `Database\Factories\PaymentFactory`**
      Created `database/factories/PaymentFactory.php` matching the Payment model (fillable, casts, method/status constants). Provides `pending`/`completed`/`void` state helpers; defaults `client_id`/`received_by` via nested factories. Verified the `Class "Database\Factories\PaymentFactory" not found` failures are resolved. Remaining Payments.feature failures are undefined-step / missing-prerequisite issues (covered by task 8).

- [x] **Fix `features/Expenses/Expenses.feature:38` — "Delete" link not found**
      Root cause: `layouts/app.blade.php` never yielded the `header` section, so the entire `@section('header')` block in `expenses/show.blade.php` (containing Edit/Delete/Submit/Mark-as-Paid buttons) was silently discarded. Added `@yield('header')` to the layout above `@yield('content')`. Also: defaulted `ExpenseFactory` to `draft` status (so `canBeDeleted()` is true), taught `iClickForTheExpense` to fall back to finding a `<button>` by visible text (Mink `pressButton` matches by name/id/value, not inner text), and made `iConfirmTheDeletion` a no-op since the delete form submits immediately in the JS-less driver. Verified: Expenses.feature:38 now passes all defined steps (1 undefined assertion remains for task 8).

- [ ] **Fix `features/Projects/Projects.feature:34` — "Submit for Approval" button not found**
      Fails: `Button with id|name|title|alt|value "Submit for Approval" not found (ElementNotFoundException)`. The button is not present on the rendered page.

- [ ] **Fix `features/Reconciliation/Reconciliation.feature:22` — `attachFileToField` undefined method**
      Fails: `Fatal error: Call to undefined method PHPUnit\Framework\Assert::attachFileToField()`. `FeatureContext::iUploadAWiseStatementCsvFile` / `iSelectAFile` call `attachFileToField` which doesn't exist on the Mink session in this context.

- [ ] **Fix `features/Reconciliation/Reconciliation.feature:31` — "Auto-Match" button not found**
      Fails: `Button with id|name|title|alt|value "Auto-Match" not found (ElementNotFoundException)`. The button is not rendered on the reconciliation page.

- [ ] **Define 50 undefined behat steps / unimplemented snippets**
      210 undefined steps; many reports/feature steps throw `PendingException` or are undefined (e.g. `the entry should balance (debits equal credits)` cannot be auto-snippet'd). Implement the undefined step definitions in `FeatureContext`.

## Failed scenarios

| Feature | Scenario line | Error |
|---|---|---|
| Clients/Clients.feature | :5 | InvoiceFactory not found |
| Clients/Clients.feature | :24 | InvoiceFactory not found |
| Clients/Clients.feature | :34 | InvoiceFactory not found |
| CreditNotes/CreditNotes.feature | :18 | InvoiceFactory not found |
| CreditNotes/CreditNotes.feature | :27 | InvoiceFactory not found |
| Documents/Documents.feature | :5 | InvoiceFactory not found |
| Documents/Documents.feature | :25 | InvoiceFactory not found |
| Documents/Documents.feature | :32 | InvoiceFactory not found |
| Expenses/Expenses.feature | :38 | "Delete" link not found |
| Invoices/AdvancedInvoices.feature | :16 | InvoiceFactory not found |
| Invoices/AdvancedInvoices.feature | :25 | InvoiceFactory not found |
| Invoices/AdvancedInvoices.feature | :34 | InvoiceFactory not found |
| Invoices/Estimates.feature | :21 | InvoiceFactory not found |
| Invoices/Estimates.feature | :29 | InvoiceFactory not found |
| Invoices/Estimates.feature | :38 | InvoiceFactory not found |
| Invoices/Invoices.feature | :18 | InvoiceFactory not found |
| Invoices/Invoices.feature | :27 | InvoiceFactory not found |
| Invoices/Invoices.feature | :48 | InvoiceFactory not found |
| Payments/AdvancedPayments.feature | :19 | InvoiceFactory not found |
| Payments/Payments.feature | :5 | InvoiceFactory not found |
| Payments/Payments.feature | :21 | PaymentFactory not found |
| Payments/Payments.feature | :29 | PaymentFactory not found |
| Payments/Payments.feature | :38 | InvoiceFactory not found |
| Projects/Projects.feature | :34 | "Submit for Approval" button not found |
| Reconciliation/Reconciliation.feature | :12 | InvoiceFactory not found |
| Reconciliation/Reconciliation.feature | :22 | `attachFileToField` undefined method |
| Reconciliation/Reconciliation.feature | :31 | "Auto-Match" button not found |
