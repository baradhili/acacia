Feature: Credit Notes
  Managing credit notes

  @credit-notes
  Scenario: User can create a credit note
    Given a client "Test Client" exists
    And I am logged in
    And I am on the new credit note page
    When I select "Test Client" as the client
    And I add a credit line with:
      | description | Returned Goods |
      | amount      | 100.00         |
    And I press "Save"
    Then I should see "Credit note created successfully"
    And the credit note should have status "Draft"

  @credit-notes
  Scenario: User can send a credit note
    Given a draft credit note exists
    And I am logged in
    And I am on the credit note details page
    When I click "Send Credit Note"
    Then the status should be "Sent"
    And an email should be sent to the client

  @credit-notes
  Scenario: User can apply credit note to invoice
    Given a credit note exists for client "Test Client" with amount 100.00
    And an invoice exists for client "Test Client" with amount 500.00
    And I am logged in
    And I am on the invoice details page
    When I click "Apply Credit"
    And I select the credit note
    And I press "Apply"
    Then the invoice balance should be reduced by 100.00
    And the credit note should be marked as "Applied"

  @credit-notes
  Scenario: User can view credit note PDF
    Given a credit note exists
    And I am logged in
    When I click "Download PDF"
    Then I should receive a PDF file
