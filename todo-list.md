# Test Failure Todo List

Generated from `php artisan test` (PHPUnit + Behat) under PHP 8.4.24.

## Summary

- PHPUnit Unit suite: 96 tests, 220 assertions passed (38 deprecations ignored by baseline, no failures)
- Behat suite: 108 scenarios (37 passed, 27 failed, 44 undefined) | 603 steps (350 passed, 27 failed, 154 undefined, 72 skipped)

## Tasks

- [x] **Fix PHPUnit unit test deprecations (38)** — DONE (no action needed)
      The 38 deprecations all originate in the `ekmungai/eloquent-ifrs` vendor package (implicit nullable params) and are already suppressed by the `.phpunit.deprecations.baseline` file referenced via `<source baseline>` in `phpunit.xml`. The PHPUnit unit suite reports `OK (96 tests, 220 assertions)` with `38 issues were ignored by baseline.` No code changes required.

- [ ] **Fix `features/Accounting/Accounting.feature:5` — "Date" text not found on journal entries page**
      Scenario "User can view journal entry list" fails with `Behat\Mink\Exception\ResponseTextException`: the text "Date" was not found anywhere on the current page. The journal entries index view likely lacks a table (or table headers). The undefined step "I should see column headers: Date, Description, Account, Debit, Credit" also needs a definition. Requires implementing the journal entries index view with the expected column headers and the `iShouldSeeTheJournalEntriesTable` / column-headers assertion steps.

- [ ] **Fix `features/Accounting/Accounting.feature:29` — "Save" button not found on journal entry form**
      Scenario "Journal entry must balance" fails with `Behat\Mink\Exception\ElementNotFoundException`: button with id|name|title|alt|value "Save" not found. The new journal entry page form lacks a "Save" submit button. Also requires the undefined steps for debit/credit line entry and the "Debits must equal credits" error message to be implemented. Add a "Save" button to the journal entry create form and implement the balancing validation.

- [ ] **Fix `features/Auth/EmailVerification.feature:24` — ambiguous step match for verification page**
      Scenario "User can resend verification email" fails: the step "I am on the verification required page" ambiguously matches both the regex `iAmOnThePage` (`/^I am on the (.+) page$/`) and the explicit `iAmOnTheVerificationRequiredPage`. Disambiguate the step definitions (e.g. tighten the regex so it does not capture "verification required", or remove the explicit step and route it through the generic one).

- [ ] **Fix `features/CreditNotes/CreditNotes.feature:5, :18, :27` — undefined credit note step definitions**
      Scenarios "User can create a credit note" (:5), "User can send a credit note" (:18), and "User can apply credit note to invoice" (:27) fail with undefined steps: `the credit note should have status "Draft"`, `Then the status should be "Sent"`, `And I select the credit note`, `Then the invoice balance should be reduced by 100.00`, `And the credit note should be marked as "Applied"`, `Given a credit note exists`, `And I add a credit line:`. Implement these step definitions in `FeatureContext` and ensure the corresponding credit note controller/views exist.

- [ ] **Fix `features/Documents/Documents.feature:5, :25, :32` — undefined document step definitions**
      Scenarios "User can upload a document to an invoice" (:5), "User can download a document" (:25), and "User can delete a document" (:32) fail with undefined steps: `Then I should receive a PDF file`, `And I enter document name "Invoice Receipt"`, `And the document should appear in the attachments list`, `When I attach document "receipt.pdf" with name "Expense Receipt"`, `Then the document should be linked to the expense`, `Then I should receive the original file`, `Then the document should be removed from attachments`. Implement these steps and the document upload/download/delete controller & views.

- [ ] **Fix `features/Expenses/Expenses.feature:5` — undefined step "the expense should appear in the list"**
      Scenario "User can create a new expense" fails because the assertion `And the expense should appear in the list` is undefined. Define the step to verify the newly created expense renders in the expense list view.

- [ ] **Fix `features/Expenses/Expenses.feature:47` — undefined steps for mark expense as paid**
      Scenario "User can mark expense as paid" fails with undefined steps: `Given an expense exists with status "Pending"`, `And I enter the payment date`, `Then the expense status should be "Paid"`. Define these steps and ensure the expense show page has a "Mark as Paid" action and payment date input, with the status transition implemented.

- [ ] **Fix `features/Invoices/Invoices.feature:5` — undefined invoice creation steps**
      Scenario "User can create a new invoice" fails with undefined steps: `When I select "Test Client" as the client`, `And I add an invoice line with:`, `And the invoice should have status "Draft"`. Define these steps and ensure the invoice create form supports client selection and line-item entry, with draft status on creation.

- [ ] **Fix `features/Invoices/Invoices.feature:48` — undefined invoice PDF steps**
      Scenario "User can view invoice PDF" fails with undefined steps: `And the filename should contain "Invoice"`, `Then I should receive a PDF file`. Define these steps and ensure the invoice show page has a PDF download action that produces a file whose name contains "Invoice".

- [ ] **Fix `features/Invoices/AdvancedInvoices.feature:25, :34` — undefined duplicate/void invoice steps**
      Scenarios "User can duplicate an invoice" (:25) and "User can void an invoice" (:34) fail with undefined steps: `Then a new draft invoice should be created`, `And it should have the same line items`, `Then the invoice status should be "Void"`, `And the invoice should be marked inactive`. Define these steps and implement duplicate/void controller actions.

- [ ] **Fix `features/Invoices/Estimates.feature:5` — undefined estimate creation steps**
      Scenario "User can create an estimate" fails with undefined steps: `Given I am creating a new invoice`, `When I add line items:`, `Then the subtotal should be 350.00`, `And with 10% tax, total should be 385.00`, `And the total should be 8000.00`, `Then a new invoice should be created`, `And it should contain the estimate line items`, `And the client clicks "Accept" on the estimate`, `Then the estimate status should be "Accepted"`. Define these steps and implement the estimate create/convert/accept flows.

- [ ] **Fix `features/Invoices/RecurringInvoices.feature:19, :28, :37, :47` — undefined recurring invoice steps**
      Scenarios "User can pause a recurring invoice" (:19), "User can resume a paused recurring invoice" (:28), "User can edit a recurring invoice template" (:37), and "User can delete a recurring invoice" (:47) fail with undefined steps: `When I create an invoice with recurrence:`, `Then a recurring invoice profile should be created`, `And invoices should be generated automatically`, `And the next invoice should be scheduled for 2024-02-01`, `Then the recurring schedule should be paused`, `And no new invoices should be generated`, `Then the recurring schedule should resume`, `And invoices should be generated again`, `And I change the amount to 600.00`, `Then future generated invoices should have the new amount`, `Then the recurring invoice should be removed`, `And future invoices should not be generated`, `Given a client exists with name "Test Client"`, `When I go to create a recurring invoice`, `And I add line item "Monthly retainer" with amount 500.00`. Define these steps and implement the recurring invoice scheduling/pause/resume/edit/delete controller logic.

- [ ] **Fix `features/Payments/Payments.feature:38` — undefined partial payment steps**
      Scenario "Partial payment reduces invoice balance" fails with undefined steps: `Given an invoice exists with balance 50.00`, `Then the invoice balance should be 500.00`, `And the invoice status should be "Partially Paid"`. Define these steps and ensure partial payments update the invoice balance and transition status to "Partially Paid".

- [ ] **Fix `features/Payments/AdvancedPayments.feature:19` — undefined late fee steps**
      Scenario "User can apply late fees" fails with undefined steps: `And I enter late fee amount 25.00`, `Then the invoice total should increase by 25.00`. Define these steps and implement late-fee application that increases the invoice total.

- [ ] **Fix `features/Projects/Projects.feature:19` — undefined time entry form steps**
      Scenario "User can create a time entry" fails with undefined steps: `And I fill in the time entry form with:`, `Then the hours should be recorded`. Define these steps and ensure the time entry create form accepts project/hours/description and persists the entry.

- [ ] **Fix `features/Reconciliation/Reconciliation.feature:22` — undefined Wise CSV import assertion**
      Scenario "User can import Wise CSV" fails with the undefined step `And transactions should appear in the list`. Define the assertion step to verify imported BankTransaction rows render in the reconciliation list view.

- [ ] **Fix `features/Reports/IfrsReports.feature:33` — "Export to Excel" link not found**
      Scenario "User can export IFRS report to Excel" fails with `Behat\Mink\Exception\ElementNotFoundException`: link "Export to Excel" not found on the IFRS balance sheet page. Add an "Export to Excel" link to the IFRS report view and implement the Excel export controller action.

- [ ] **Fix `features/Reports/Reports.feature:30` — "Export to PDF" link not found**
      Scenario "User can export report to PDF" fails with `Behat\Mink\Exception\ElementNotFoundException`: link "Export to PDF" not found on the time-by-client report page. Add an "Export to PDF" link to the reports view and implement the PDF export controller action.

- [ ] **Fix `features/Reports/Reports.feature:38` — "from_date" form field not found**
      Scenario "User can filter report by date range" fails with `Behat\Mink\Exception\ElementNotFoundException`: form field "from_date" not found on the time-by-client report page. Add `from_date`/`to_date` filter inputs and an "Apply Filter" button to the report view, and implement date-range filtering in the controller.

## Failed scenarios

| Feature | Scenario line | Scenario | Error |
|---|---|---|---|
| Accounting/Accounting.feature | :5 | User can view journal entry list | "Date" text not found on page |
| Accounting/Accounting.feature | :29 | Journal entry must balance | "Save" button not found |
| Auth/EmailVerification.feature | :24 | User can resend verification email | Ambiguous step match |
| CreditNotes/CreditNotes.feature | :5 | User can create a credit note | Undefined steps |
| CreditNotes/CreditNotes.feature | :18 | User can send a credit note | Undefined steps |
| CreditNotes/CreditNotes.feature | :27 | User can apply credit note to invoice | Undefined steps |
| Documents/Documents.feature | :5 | User can upload a document to an invoice | Undefined steps |
| Documents/Documents.feature | :25 | User can download a document | Undefined steps |
| Documents/Documents.feature | :32 | User can delete a document | Undefined steps |
| Expenses/Expenses.feature | :5 | User can create a new expense | Undefined step |
| Expenses/Expenses.feature | :47 | User can mark expense as paid | Undefined steps |
| Invoices/Invoices.feature | :5 | User can create a new invoice | Undefined steps |
| Invoices/Invoices.feature | :48 | User can view invoice PDF | Undefined steps |
| Invoices/AdvancedInvoices.feature | :25 | User can duplicate an invoice | Undefined steps |
| Invoices/AdvancedInvoices.feature | :34 | User can void an invoice | Undefined steps |
| Invoices/Estimates.feature | :5 | User can create an estimate | Undefined steps |
| Invoices/RecurringInvoices.feature | :19 | User can pause a recurring invoice | Undefined steps |
| Invoices/RecurringInvoices.feature | :28 | User can resume a paused recurring invoice | Undefined steps |
| Invoices/RecurringInvoices.feature | :37 | User can edit a recurring invoice template | Undefined steps |
| Invoices/RecurringInvoices.feature | :47 | User can delete a recurring invoice | Undefined steps |
| Payments/Payments.feature | :38 | Partial payment reduces invoice balance | Undefined steps |
| Payments/AdvancedPayments.feature | :19 | User can apply late fees | Undefined steps |
| Projects/Projects.feature | :19 | User can create a time entry | Undefined steps |
| Reconciliation/Reconciliation.feature | :22 | User can import Wise CSV | Undefined assertion step |
| Reports/IfrsReports.feature | :33 | User can export IFRS report to Excel | "Export to Excel" link not found |
| Reports/Reports.feature | :30 | User can export report to PDF | "Export to PDF" link not found |
| Reports/Reports.feature | :38 | User can filter report by date range | "from_date" field not found |

## Undefined Behat steps (snippets to define)

The full list of undefined steps reported by Behat (154 total):

- And I should see column headers: Date, Description, Account, Debit, Credit
- When I fill in the journal entry form with:
- And I add a credit line:
- And the entry should balance (debits equal credits)
- And I should see column headers: Name, Email, Phone, Actions
- And the credit note should have status "Draft"
- Then the status should be "Sent"
- And I select the credit note
- Then the invoice balance should be reduced by 100.00
- And the credit note should be marked as "Applied"
- Given a credit note exists
- Then I should receive a PDF file
- And I enter document name "Invoice Receipt"
- And the document should appear in the attachments list
- When I attach document "receipt.pdf" with name "Expense Receipt"
- Then the document should be linked to the expense
- Then I should receive the original file
- Then the document should be removed from attachments
- And the expense should appear in the list
- Then I should see the expense description
- And I should see the amount
- And I should see the expense date
- And I change the amount to "200.00"
- Then I should see the updated amount
- Then the expense should be removed from the list
- Then the expense status should be "Paid"
- And I should see links to: Dashboard, Clients, Invoices, Expenses, Projects, Reports
- Then I should be on the dashboard page
- Then I should be on the clients page
- Then I should be on the invoices page
- When I click on my profile name
- Then I should see dropdown with: Profile, Settings, Logout
- And I am on the client details page for "Test Client"
- Then I should see breadcrumbs: Home > Clients > Test Client
- Given a client exists
- When I create an invoice with recurrence:
- Then a recurring invoice profile should be created
- And invoices should be generated automatically
- When I select invoices 1, 2, 3
- Then all selected invoices should be marked as sent
- Then a new draft invoice should be created
- And it should have the same line items
- Then the invoice status should be "Void"
- And the invoice should be marked inactive
- Given I am creating a new invoice
- When I add line items:
- Then the subtotal should be 350.00
- And with 10% tax, total should be 385.00
- And the total should be 8000.00
- Then a new invoice should be created
- And it should contain the estimate line items
- And the client clicks "Accept" on the estimate
- Then the estimate status should be "Accepted"
- Then I should see the client name
- And I should see the line items
- And I should see the total amount
- Then the invoice status should be "Sent"
- Given a sent invoice exists for client "Test Client"
- Then the invoice status should be "Paid"
- And the filename should contain "Invoice"
- Given a client exists with name "Test Client"
- When I go to create a recurring invoice
- And I add line item "Monthly retainer" with amount 500.00
- And the next invoice should be scheduled for 2024-02-01
- Then the recurring schedule should be paused
- And no new invoices should be generated
- Then the recurring schedule should resume
- And invoices should be generated again
- And I change the amount to 600.00
- Then future generated invoices should have the new amount
- Then the recurring invoice should be removed
- And future invoices should not be generated
- Given an invoice exists with amount 1500.00
- And I select the invoice
- And the invoice should be marked as paid
- And two payment records should be created
- And I enter late fee amount 25.00
- Then the invoice total should increase by 25.00
- Given an invoice exists with balance 50.00
- And I enter the write off amount 50.00
- And I select reason "Bad Debt"
- Then the invoice balance should be 0.00
- And the invoice should be marked as "Written Off"
- Given payments exist across multiple months
- Then I should see total payments by month
- And I should see breakdown by payment method
- And the payment should appear in the list
- Then I should see all payments
- And each payment should show date, amount, and method
- Then I should see only payments within the date range
- Then the invoice balance should be 500.00
- And the invoice status should be "Partially Paid"
- When I fill in project details:
- And I add phase "Discovery" with budget 2000.00
- And I add phase "Design" with budget 3000.00
- And I add phase "Development" with budget 5000.00
- Then the project should be created with 3 phases
- And the total budget should equal the project budget
- When I create a time entry:
- Then the hours should be tracked against the Design phase
- And I can see phase budget utilization
- And the "Design" phase has all time approved
- And I am logged in as project manager
- When I click "Complete Phase" on Design
- Then the Design phase should be marked as complete
- And the next phase can begin
- Given a project with budget 10000.00 exists
- And time entries totaling 5000.00 have been approved
- And expenses totaling 1000.00 have been added
- When I view the project
- Then I should see budget utilization at 60%
- And I should see remaining budget of 4000.00
- Given a project exists with budget 5000.00
- When I create a purchase order:
- Then the purchase order should be linked to the project
- And project committed budget should increase by 1000.00
- When I fill in the project form with:
- And the project should appear in the project list
- And I fill in the time entry form with:
- And the hours should be recorded
- Then I should see bank transactions list
- And I should see matched and unmatched items
- When I drag the transaction to match with the invoice
- Then the transaction should be marked as matched
- And the invoice should show as paid
- And a transaction is matched to an invoice
- Then a cash receipt should be created
- Then I should see assets categorized as current and non-current
- And I should see liabilities categorized as current and non-current
- And assets should equal liabilities plus equity
- Then I should see revenue breakdown
- And I should see expense breakdown
- And I should see net profit or loss
- Then I should see operating activities
- And I should see investing activities
- And I should see financing activities
- And I should see net change in cash
- Then I should receive an Excel file
- And it should contain formatted financial data
- Then I should see total hours per client
- And I should see breakdown by project
- Then I should see total hours per staff member
- Then I should see revenue per project
- And I should see costs per project
- And I should see profit margin per project
- And it should contain the report data
- Then I should see only entries within the date range

## Phase 2 task progress

- [x] **TASK-9: Resolve ambiguous page-navigation steps colliding with the generic `I am on the :path page` step**
      Removed the redundant specific `iAmOnTheWiseImportPage` / `iAmOnTheVerificationRequiredPage` methods (re-introduced by a parallel session) and routed their URLs through the generic step's `$pageMap` (`'wise import' => '/reconciliation/import'`, `'verification required' => '/verify-email'`). Fixes Reconciliation:22 (regression of TASK-6) and Auth/EmailVerification:24. Suite 37 -> 39 passed.

- [x] **TASK-10: Make "…for client X" given-steps auto-create the client + fix Payments:38 modal-submit driver error**
      The null `->id` bug was already fixed by the parallel remote session (steps now create the client when absent), moving Invoices:18 and Payments :5/:21/:29 out of failed. The remaining Payments:38 failure came from `iRecordAPartialPaymentOf` calling `pressButton('Record Payment')`, which matched the JS-only `<button type="button">` modal opener and threw `KernelDriver supports clicking on links and submit or reset buttons only. But "button" provided`. Rewrote the step to fill the `amount` field, walk up to its containing modal `<form>`, and press that form's submit `<button>` by matching button text. Payments:38 passes given+action steps now; suite 25 -> 24 failed.

- [x] **TASK-11: Create CreditNoteFactory + EstimateFactory; stop injecting a `type` column into invoices**
      Original root cause (`type` column on `invoices`) fully resolved by the parallel remote session, which added `CreditNoteFactory` + `EstimateFactory` and rewired the FeatureContext steps to the correct models/tables (Estimates :21/:29/:38 out of failed). Remaining gap I fixed: `iAmOnThePage("new credit note" / "new estimate")` fell through to a `/new-credit-note` (404) fallback, so the create forms never loaded and `client_id` was not found. Added pageMap entries `'new credit note' => '/credit-notes/create'` and `'new estimate' => '/estimates/create'` (plus `credit notes`/`estimates` index entries); create forms now load and the client select works. (A speculative `iPress` contains-text fallback was tried and reverted — it regressed 4 unrelated scenarios.)
      **Out of scope (genuine unbuilt-feature gaps):** CreditNotes :5/:18/:27 and Estimates :5 still fail because (a) the create forms build line items via a JS `addItemBtn` (`<button type="button">`) the JS-less KernelDriver cannot run; (b) CreditNotes:18 needs a "Send Credit Note" route+controller+view (no `credit-notes.send` route); (c) CreditNotes:27 needs an "Apply Credit" link on the invoice show view. Left for a follow-up.

- [ ] **TASK-12: Fix missing "Download"/"Download PDF" navigation in document/PDF scenarios**
      The invoice show view already renders a "Download PDF" link and per-document "Download" links, but the Invoices:48 and Documents:25 scenarios click "Download PDF"/"Download" without first navigating to the invoice details page, so the link isn't on the current page → `Link … not found`. Fix: make `iClick("Download PDF")` (for invoices) and `iClickOnTheDocument("Download")` navigate to the relevant invoice's show page before clicking. Verify Invoices:48 and Documents:25 pass their defined steps.

### Final goal
All tasks complete → remaining failures (for scenarios whose steps are defined) cleared to zero; scenarios with still-undefined steps move to "undefined", not "failed".
