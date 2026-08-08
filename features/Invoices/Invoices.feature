Feature: Invoices
  Managing invoices and billing

  @invoices
  Scenario: User can create a new invoice
    Given a client "Test Client" exists with email "test@example.com"
    And I am logged in
    And I am on the new invoice page
    When I select "Test Client" as the client
    And I add an invoice line with:
      | description | Hours | Rate   | Amount |
      | Design Work | 10    | 150.00 | 1500   |
    And I press "Save Invoice"
    Then I should see "Invoice created successfully"
    And the invoice should have status "Draft"

  @invoices
  Scenario: User can view invoice details
    Given an invoice exists for client "Test Client"
    And I am logged in
    When I am on the invoice details page
    Then I should see the client name
    And I should see the line items
    And I should see the total amount

  @invoices
  Scenario: User can send an invoice
    Given a draft invoice exists
    And I am logged in
    And I am on the invoice details page
    When I click "Send Invoice"
    Then the invoice status should be "Sent"
    And an email should be sent to the client

  @invoices
  Scenario: User can mark invoice as paid
    Given a sent invoice exists for client "Test Client"
    And I am logged in
    And I am on the invoice details page
    When I click "Mark as Paid"
    And I select payment method "Bank Transfer"
    And I enter the payment date
    And I press "Save Payment"
    Then the invoice status should be "Paid"
    And a payment record should be created

  @invoices
  Scenario: User can view invoice PDF
    Given an invoice exists
    And I am logged in
    When I click "Download PDF"
    Then I should receive a PDF file
    And the filename should contain "Invoice"
