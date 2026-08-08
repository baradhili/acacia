Feature: Registration
  User registration functionality

  @registration
  Scenario: User can register with valid email
    Given I am on the registration page
    When I fill in:
      | name                   | New User            |
      | email                  | newuser@example.com |
      | password                | SecurePass123       |
      | password_confirmation   | SecurePass123       |
    And I press "Register"
    Then I should be redirected to the dashboard
    And my account should be created

  @registration
  Scenario: Registration fails with duplicate email
    Given a user with email "existing@example.com" exists
    And I am on the registration page
    When I fill in:
      | name                   | Another User        |
      | email                  | existing@example.com |
      | password                | SecurePass123       |
      | password_confirmation   | SecurePass123       |
    And I press "Register"
    Then I should see "Email has already been taken"
    And my account should not be created

  @registration
  Scenario: Registration fails with weak password
    Given I am on the registration page
    When I fill in:
      | name                   | New User            |
      | email                  | newuser@example.com |
      | password                | 123                 |
      | password_confirmation   | 123                 |
    And I press "Register"
    Then I should see a password validation error

  @registration
  Scenario: Registration requires password confirmation
    Given I am on the registration page
    When I fill in:
      | name                   | New User            |
      | email                  | newuser@example.com |
      | password                | SecurePass123       |
    And I press "Register"
    Then I should see "password confirmation is required"
