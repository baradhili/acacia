Feature: Role Middleware
  Role-based access control

  @role-middleware
  Scenario: Admin can access admin pages
    Given I am logged in as an admin
    When I visit the admin settings page
    Then I should see the admin panel

  @role-middleware
  Scenario: Regular user cannot access admin pages
    Given I am logged in as a regular user
    When I visit the admin settings page
    Then I should see a 403 Forbidden error
    Or I should be redirected to the dashboard

  @role-middleware
  Scenario: Manager can approve time entries
    Given I am logged in as a manager
    When I visit the pending approvals page
    Then I should see time entries awaiting approval

  @role-middleware
  Scenario: Non-manager cannot approve time entries
    Given I am logged in as a regular user
    When I visit the pending approvals page
    Then I should see a 403 Forbidden error

  @role-middleware
  Scenario: User can only see their own data
    Given I am logged in as "user1@example.com"
    When I try to access invoices belonging to "user2@example.com"
    Then I should see a 403 Forbidden error
