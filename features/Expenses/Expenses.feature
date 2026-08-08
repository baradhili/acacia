Feature: Expenses
  Managing expenses and vendor bills

  @expenses
  Scenario: User can create a new expense
    Given I am logged in
    And I am on the new expense page
    When I fill in the expense form with:
      | field         | value           |
      | description   | Office Supplies |
      | amount        | 150.00          |
      | expense_date  | today           |
      | supplier      | Office Depot    |
    And I press "Save"
    Then I should see "Expense created successfully"
    And the expense should appear in the list

  @expenses
  Scenario: User can view expense details
    Given an expense exists with description "Office Supplies"
    And I am logged in
    When I am on the expense details page
    Then I should see the expense description
    And I should see the amount
    And I should see the expense date

  @expenses
  Scenario: User can edit an expense
    Given an expense exists
    And I am logged in
    And I am on the expenses page
    When I click "Edit" for the expense
    And I change the amount to "200.00"
    And I press "Save"
    Then I should see the updated amount

  @expenses
  Scenario: User can delete an expense
    Given an expense exists
    And I am logged in
    And I am on the expenses page
    When I click "Delete" for the expense
    And I confirm the deletion
    Then the expense should be removed from the list

  @expenses
  Scenario: User can mark expense as paid
    Given an expense exists with status "Pending"
    And I am logged in
    And I am on the expense details page
    When I click "Mark as Paid"
    And I enter the payment date
    And I press "Save"
    Then the expense status should be "Paid"
