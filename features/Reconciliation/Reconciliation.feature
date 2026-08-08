Feature: Reconciliation
  Bank reconciliation and Wise integration

  @reconciliation
  Scenario: User can view reconciliation page
    Given I am logged in
    And I am on the reconciliation page
    Then I should see bank transactions list
    And I should see matched and unmatched items

  @reconciliation
  Scenario: User can manually match a transaction
    Given I am logged in
    And I am on the reconciliation page
    And there is an unmatched bank transaction
    And there is an invoice awaiting payment
    When I drag the transaction to match with the invoice
    Then the transaction should be marked as matched
    And the invoice should show as paid

  @reconciliation
  Scenario: User can import Wise CSV
    Given I am logged in
    And I am on the Wise import page
    When I upload a Wise statement CSV file
    And I press "Import"
    Then I should see "Import successful"
    And transactions should appear in the list

  @reconciliation
  Scenario: User can auto-match transactions
    Given I am logged in
    And I am on the reconciliation page
    And there are unmatched transactions and invoices
    When I click "Auto-Match"
    Then matching transactions should be paired automatically
    And unmatched items should remain in the list

  @reconciliation
  Scenario: User can create cash receipt from match
    Given I am logged in
    And I am on the reconciliation page
    And a transaction is matched to an invoice
    When I click "Create Cash Receipt"
    Then a cash receipt should be created
    And the invoice should be marked as paid
