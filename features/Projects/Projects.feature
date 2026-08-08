Feature: Projects
  Managing projects and time tracking

  @projects
  Scenario: User can create a new project
    Given a client "Test Client" exists
    And I am logged in
    And I am on the new project page
    When I fill in the project form with:
      | field       | value         |
      | name        | Website Redesign |
      | client      | Test Client   |
      | budget      | 10000.00      |
    And I press "Save"
    Then I should see "Project created successfully"
    And the project should appear in the project list

  @projects
  Scenario: User can create a time entry
    Given a project "Website Redesign" exists
    And I am logged in
    And I am on the time entries page
    When I click "Add Time Entry"
    And I fill in the time entry form with:
      | field       | value           |
      | project     | Website Redesign |
      | hours       | 4.5             |
      | description | Logo design     |
    And I press "Save"
    Then I should see "Time entry created"
    And the hours should be recorded

  @projects
  Scenario: User can submit time entry for approval
    Given a draft time entry exists
    And I am logged in
    When I click "Submit for Approval"
    Then the time entry status should be "Submitted"

  @projects
  Scenario: User can approve time entry
    Given a submitted time entry exists
    And I am logged in as manager
    When I click "Approve" on the time entry
    Then the time entry status should be "Approved"
    And approval timestamp should be recorded

  @projects
  Scenario: User can reject time entry with reason
    Given a submitted time entry exists
    And I am logged in as manager
    When I click "Reject" on the time entry
    And I enter rejection reason "Please clarify hours"
    And I press "Submit Rejection"
    Then the time entry status should be "Rejected"
    And rejection reason should be visible
