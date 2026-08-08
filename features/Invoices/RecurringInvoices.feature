Feature: Recurring Invoices
  Managing recurring invoice templates

  @recurring
  Scenario: User can create a recurring invoice template
    Given a client exists with name "Test Client"
    And I am logged in
    When I go to create a recurring invoice
    And I fill in:
      | client       | Test Client       |
      | frequency    | Monthly           |
      | start_date   | 2024-01-01        |
    And I add line item "Monthly retainer" with amount 500.00
    And I press "Save Recurring"
    Then I should see "Recurring invoice created"
    And the next invoice should be scheduled for 2024-02-01

  @recurring
  Scenario: User can pause a recurring invoice
    Given a recurring invoice exists
    And I am logged in
    And I am on the recurring invoice details page
    When I click "Pause"
    Then the recurring schedule should be paused
    And no new invoices should be generated

  @recurring
  Scenario: User can resume a paused recurring invoice
    Given a recurring invoice is paused
    And I am logged in
    And I am on the recurring invoice details page
    When I click "Resume"
    Then the recurring schedule should resume
    And invoices should be generated again

  @recurring
  Scenario: User can edit a recurring invoice template
    Given a recurring invoice exists
    And I am logged in
    And I am on the recurring invoice details page
    When I click "Edit Template"
    And I change the amount to 600.00
    And I press "Save"
    Then future generated invoices should have the new amount

  @recurring
  Scenario: User can delete a recurring invoice
    Given a recurring invoice exists
    And I am logged in
    And I am on the recurring invoice details page
    When I click "Delete Recurring"
    And I confirm the deletion
    Then the recurring invoice should be removed
    And future invoices should not be generated
