Feature: Authentication
  Users should be able to register, login, and logout

  @auth
  Scenario: User can register with valid credentials
    Given I am on the registration page
    When I fill in the registration form with:
      | field       | value                |
      | name        | Test User            |
      | email       | test@example.com     |
      | password    | password123          |
      | password_confirmation | password123 |
    And I press "Register"
    Then I should be redirected to the dashboard
    And I should see "Dashboard" in the navigation

  @auth
  Scenario: User can login with valid credentials
    Given a user exists with email "test@example.com" and password "password123"
    And I am on the login page
    When I fill in "email" with "test@example.com"
    And I fill in "password" with "password123"
    And I press "Log in"
    Then I should be redirected to the dashboard
    And I should see my name in the navigation

  @auth
  Scenario: User cannot login with invalid credentials
    Given I am on the login page
    When I fill in "email" with "wrong@example.com"
    And I fill in "password" with "wrongpassword"
    And I press "Log in"
    Then I should see "Invalid credentials" error message

  @auth
  Scenario: User can logout
    Given I am logged in
    When I click "Logout" in the navigation
    Then I should be redirected to the login page
    And I should see "Login" button

  @auth
  Scenario: Login page requires authentication for protected pages
    Given I am on "/dashboard"
    Then I should be redirected to the login page
