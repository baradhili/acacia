Feature: IFRS Reports
  International Financial Reporting Standards reports

  @ifrs-reports
  Scenario: User can view IFRS balance sheet
    Given I am logged in
    And journal entries exist for the period
    When I am on the IFRS balance sheet report page
    Then I should see assets categorized as current and non-current
    And I should see liabilities categorized as current and non-current
    And assets should equal liabilities plus equity

  @ifrs-reports
  Scenario: User can view IFRS income statement
    Given I am logged in
    And revenue and expense transactions exist
    When I am on the IFRS income statement page
    Then I should see revenue breakdown
    And I should see expense breakdown
    And I should see net profit or loss

  @ifrs-reports
  Scenario: User can view IFRS cash flow statement
    Given I am logged in
    And cash transactions exist
    When I am on the IFRS cash flow statement page
    Then I should see operating activities
    And I should see investing activities
    And I should see financing activities
    And I should see net change in cash

  @ifrs-reports
  Scenario: User can export IFRS report to Excel
    Given I am on the IFRS balance sheet page
    And I am logged in
    When I click "Export to Excel"
    Then I should receive an Excel file
    And it should contain formatted financial data
