Feature: Estimates
  Managing project estimates/quotes

  @estimates
  Scenario: User can create an estimate
    Given a client "Test Client" exists
    And I am logged in
    And I am on the new estimate page
    When I select "Test Client" as the client
    And I add estimate line:
      | description      | Hours | Rate   | Amount |
      | Design Phase     | 20    | 150.00 | 3000   |
    And I add estimate line:
      | description      | Hours | Rate   | Amount |
      | Development      | 40    | 125.00 | 5000   |
    And I press "Save Estimate"
    Then I should see "Estimate created successfully"
    And the total should be 8000.00

  @estimates
  Scenario: User can send an estimate
    Given an estimate exists
    And I am logged in
    And I am on the estimate details page
    When I click "Send Estimate"
    Then the status should be "Sent"

  @estimates
  Scenario: User can convert estimate to invoice
    Given an approved estimate exists
    And I am logged in
    And I am on the estimate details page
    When I click "Convert to Invoice"
    Then a new invoice should be created
    And it should contain the estimate line items

  @estimates
  Scenario: User can accept an estimate
    Given a sent estimate exists
    And the client receives the estimate email
    And the client clicks "Accept" on the estimate
    Then the estimate status should be "Accepted"
