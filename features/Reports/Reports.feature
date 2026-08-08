Feature: Reports
  Generating and viewing reports

  @reports
  Scenario: User can view time by client report
    Given time entries exist for multiple clients
    And I am logged in
    When I am on the time by client report page
    Then I should see total hours per client
    And I should see breakdown by project

  @reports
  Scenario: User can view time by staff report
    Given time entries exist for multiple staff members
    And I am logged in
    When I am on the time by staff report page
    Then I should see total hours per staff member
    And I should see breakdown by project

  @reports
  Scenario: User can view project profitability report
    Given projects with billable time and expenses exist
    And I am logged in
    When I am on the project profitability report page
    Then I should see revenue per project
    And I should see costs per project
    And I should see profit margin per project

  @reports
  Scenario: User can export report to PDF
    Given I am on the time by client report page
    And I am logged in
    When I click "Export to PDF"
    Then I should receive a PDF file
    And it should contain the report data

  @reports
  Scenario: User can filter report by date range
    Given time entries exist across multiple months
    And I am logged in
    And I am on the time by client report page
    When I set date range from "2024-01-01" to "2024-01-31"
    And I press "Apply Filter"
    Then I should see only entries within the date range
