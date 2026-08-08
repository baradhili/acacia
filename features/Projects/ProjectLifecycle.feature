Feature: Project Lifecycle
  Managing project phases and milestones

  @project-lifecycle
  Scenario: User can create a project with phases
    Given a client exists
    And I am logged in
    And I am on the new project page
    When I fill in project details:
      | name | Website Redesign |
      | client | Test Client |
      | budget | 10000.00 |
    And I add phase "Discovery" with budget 2000.00
    And I add phase "Design" with budget 3000.00
    And I add phase "Development" with budget 5000.00
    And I press "Save Project"
    Then the project should be created with 3 phases
    And the total budget should equal the project budget

  @project-lifecycle
  Scenario: User can track time by phase
    Given a project with phases exists
    And I am logged in
    And I am on the time entry page
    When I create a time entry:
      | project | Website Redesign |
      | phase   | Design |
      | hours   | 8 |
    Then the hours should be tracked against the Design phase
    And I can see phase budget utilization

  @project-lifecycle
  Scenario: User can mark phase as complete
    Given a project with phases exists
    And the "Design" phase has all time approved
    And I am logged in as project manager
    When I click "Complete Phase" on Design
    Then the Design phase should be marked as complete
    And the next phase can begin

  @project-lifecycle
  Scenario: Project shows budget utilization
    Given a project with budget 10000.00 exists
    And time entries totaling 5000.00 have been approved
    And expenses totaling 1000.00 have been added
    And I am logged in
    When I view the project
    Then I should see budget utilization at 60%
    And I should see remaining budget of 4000.00

  @project-lifecycle
  Scenario: User can allocate purchase orders to project
    Given a project exists with budget 5000.00
    And I am logged in
    When I create a purchase order:
      | description | Software License |
      | amount | 1000.00 |
      | project | Test Project |
    Then the purchase order should be linked to the project
    And project committed budget should increase by 1000.00
