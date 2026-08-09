# Test Failure Todo List

Generated from `php artisan test` (PHPUnit + Behat).

## Summary

- PHPUnit Unit suite: 96 tests, 220 assertions passed (38 deprecations, no failures)
- Behat suite: 108 scenarios (31 passed, 27 failed, 50 undefined) | 603 steps (228 passed, 27 failed, 210 undefined, 138 skipped)

## Tasks

- [ ] **Fix PHPUnit unit test deprecations (38)**
      PHPUnit unit suite passes but emits 38 deprecations under PHP 8.4. Investigate and resolve deprecation warnings so the unit suite is clean.

- [ ] **Create missing `Database\Factories\InvoiceFactory`**
      Dominant behat failure (23 scenarios): `Fatal error: Class "Database\Factories\InvoiceFactory" not found`. Affects Clients, CreditNotes, Documents, Invoices (Advanced/Estimates/Invoices), Payments (Advanced/Payments), Reconciliation. Create `database/factories/InvoiceFactory.php` matching the Invoice model.

- [ ] **Create missing `Database\Factories\PaymentFactory`**
      2 behat scenarios in `features/Payments/Payments.feature` (:21, :29) fail with `Fatal error: Class "Database\Factories\PaymentFactory" not found`. Create `database/factories/PaymentFactory.php` matching the Payment model.

- [ ] **Fix `features/Expenses/Expenses.feature:38` — "Delete" link not found**
      Fails: `Link with id|title|alt|text "Delete" not found (Behat\Mink\Exception\ElementNotFoundException)`. View/step expects a Delete link that is not rendered.

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
