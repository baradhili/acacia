Feature: Advanced Invoices
  Advanced invoice operations and bulk actions

  @advanced-invoices
  Scenario: User can create recurring invoice
    Given I am logged in
    And a client exists
    When I create an invoice with recurrence:
      | frequency | monthly |
      | start_date | today |
      | end_date | +1 year |
    Then a recurring invoice profile should be created
    And invoices should be generated automatically

  @advanced-invoices
  Scenario: User can bulk send invoices
    Given multiple draft invoices exist
    And I am logged in
    And I am on the invoices list page
    When I select invoices 1, 2, 3
    And I click "Bulk Send"
    Then all selected invoices should be marked as sent

  @advanced-invoices
  Scenario: User can duplicate an invoice
    Given an invoice exists
    And I am logged in
    And I am on the invoice details page
    When I click "Duplicate"
    Then a new draft invoice should be created
    And it should have the same line items

  @advanced-invoices
  Scenario: User can void an invoice
    Given a sent invoice exists
    And I am logged in
    And I am on the invoice details page
    When I click "Void Invoice"
    And I confirm the void action
    Then the invoice status should be "Void"
    And the invoice should be marked inactive

  @advanced-invoices
  Scenario: Invoice auto-calculates totals
    Given I am creating a new invoice
    When I add line items:
      | description | quantity | unit_price |
      | Item A      | 2        | 100.00     |
      | Item B      | 3        | 50.00      |
    Then the subtotal should be 350.00
    And with 10% tax, total should be 385.00
