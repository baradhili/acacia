Feature: Accounting
  Managing journal entries and accounting records

  @accounting
  Scenario: User can view journal entry list
    Given I am logged in
    And I am on the journal entries page
    Then I should see the journal entries table
    And I should see column headers: Date, Description, Account, Debit, Credit

  @accounting
  Scenario: User can create a journal entry
    Given I am logged in
    And I am on the new journal entry page
    When I fill in the journal entry form with:
      | description | Test Journal Entry |
      | date        | today              |
    And I add a debit line:
      | account      | debit     |
      | Cash         | 1000.00   |
    And I add a credit line:
      | account      | credit    |
      | Revenue      | 1000.00   |
    And I press "Save"
    Then I should see "Journal entry created successfully"
    And the entry should balance (debits equal credits)

  @accounting
  Scenario: Journal entry must balance
    Given I am logged in
    And I am on the new journal entry page
    When I add a debit line with amount 100.00
    And I add a credit line with amount 50.00
    And I press "Save"
    Then I should see an error message "Debits must equal credits"
