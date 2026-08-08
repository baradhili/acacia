Feature: Navigation
  Application navigation and menu structure

  @navigation
  Scenario: Navigation menu displays for logged in user
    Given I am logged in
    Then I should see the main navigation menu
    And I should see links to: Dashboard, Clients, Invoices, Expenses, Projects, Reports

  @navigation
  Scenario: Navigation menu is hidden for guests
    Given I am logged out
    Then I should not see the main navigation menu

  @navigation
  Scenario: User can access dashboard from menu
    Given I am logged in
    When I click "Dashboard" in the navigation
    Then I should be on the dashboard page

  @navigation
  Scenario: User can access clients from menu
    Given I am logged in
    When I click "Clients" in the navigation
    Then I should be on the clients page

  @navigation
  Scenario: User can access invoices from menu
    Given I am logged in
    When I click "Invoices" in the navigation
    Then I should be on the invoices page

  @navigation
  Scenario: Profile dropdown shows user options
    Given I am logged in
    When I click on my profile name
    Then I should see dropdown with: Profile, Settings, Logout

  @navigation
  Scenario: Breadcrumbs show current location
    Given I am logged in
    And I am on the client details page for "Test Client"
    Then I should see breadcrumbs: Home > Clients > Test Client
