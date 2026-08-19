Feature: Authentication
  In order to access the ERP
  As a visitor
  I need to be able to log in and out

  Scenario: The application returns a successful response
    When I am on "/"
    Then the response status code should be 200

  Scenario: Login screen can be rendered
    When I am on "/login"
    Then the response status code should be 200

  Scenario: Users can authenticate using the login screen
    Given a user "user@example.com" exists with password "password"
    When I send a POST request to "/login" with:
      | field    | value             |
      | email    | user@example.com  |
      | password | password          |
    Then I should be redirected to "/dashboard"

  Scenario: Users cannot authenticate with an invalid password
    Given a user "user@example.com" exists with password "password"
    When I send a POST request to "/login" with:
      | field    | value            |
      | email    | user@example.com |
      | password | wrong-password   |
    Then the validation should fail on "email"
    And I should not be authenticated

  Scenario: Users can log out
    Given I am logged in as "user@example.com" with password "password"
    When I log out
    Then I should be redirected to "/"
    And I should be redirected to the login page
