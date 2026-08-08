Feature: Advanced Payments
  Advanced payment operations

  @advanced-payments
  Scenario: User can record payment with multiple methods
    Given an invoice exists with amount 1500.00
    And I am logged in
    And I am on the record payment page
    When I select the invoice
    And I fill in partial payment:
      | method        | amount |
      | Bank Transfer | 1000.00 |
      | Credit Card   | 500.00  |
    And I press "Save"
    Then the invoice should be marked as paid
    And two payment records should be created

  @advanced-payments
  Scenario: User can apply late fees
    Given an overdue invoice exists
    And I am logged in
    And I am on the invoice details page
    When I click "Add Late Fee"
    And I enter late fee amount 25.00
    And I press "Apply"
    Then the invoice total should increase by 25.00

  @advanced-payments
  Scenario: User can write off a balance
    Given an invoice exists with balance 50.00
    And I am logged in
    And I am on the invoice details page
    When I click "Write Off Balance"
    And I enter the write off amount 50.00
    And I select reason "Bad Debt"
    And I press "Confirm"
    Then the invoice balance should be 0.00
    And the invoice should be marked as "Written Off"

  @advanced-payments
  Scenario: User can view payment summary
    Given payments exist across multiple months
    And I am logged in
    When I am on the payment summary page
    Then I should see total payments by month
    And I should see breakdown by payment method
