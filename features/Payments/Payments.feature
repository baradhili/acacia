Feature: Payments
  Managing payments and receipts

  @payments
  Scenario: User can record a payment
    Given an invoice exists for client "Test Client" with amount 1500.00
    And I am logged in
    And I am on the payments page
    When I click "Record Payment"
    And I select the invoice
    And I enter the payment details:
      | field         | value           |
      | amount        | 1500.00         |
      | payment_date  | today           |
      | payment_method | Bank Transfer  |
    And I press "Save"
    Then I should see a success message
    And the payment should appear in the list

  @payments
  Scenario: User can view payment history
    Given payments exist for client "Test Client"
    And I am logged in
    And I am on the payments page
    Then I should see all payments
    And each payment should show date, amount, and method

  @payments
  Scenario: User can filter payments by date range
    Given payments exist for client "Test Client"
    And I am logged in
    And I am on the payments page
    When I set the date filter from "2024-01-01" to "2024-01-31"
    And I press "Filter"
    Then I should see only payments within the date range

  @payments
  Scenario: Partial payment reduces invoice balance
    Given an invoice exists for client "Test Client" with amount 1000.00
    And I am logged in
    And I am on the invoice details page
    When I record a partial payment of 500.00
    Then the invoice balance should be 500.00
    And the invoice status should be "Partially Paid"
