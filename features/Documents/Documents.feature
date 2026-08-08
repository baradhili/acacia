Feature: Documents
  Managing document uploads and attachments

  @documents
  Scenario: User can upload a document to an invoice
    Given an invoice exists
    And I am logged in
    And I am on the invoice details page
    When I click "Attach Document"
    And I select a file "invoice_receipt.pdf"
    And I enter document name "Invoice Receipt"
    And I press "Upload"
    Then I should see "Document uploaded successfully"
    And the document should appear in the attachments list

  @documents
  Scenario: User can upload a document to an expense
    Given an expense exists
    And I am logged in
    And I am on the expense details page
    When I attach document "receipt.pdf" with name "Expense Receipt"
    Then the document should be linked to the expense

  @documents
  Scenario: User can download a document
    Given a document is attached to an invoice
    And I am logged in
    When I click "Download" on the document
    Then I should receive the original file

  @documents
  Scenario: User can delete a document
    Given a document is attached to an invoice
    And I am logged in
    And I am on the invoice details page
    When I click "Delete" on the document
    And I confirm the deletion
    Then the document should be removed from attachments
