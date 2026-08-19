Feature: Registration
  In order to join the ERP
  As a visitor
  I need to be able to register an account

  Scenario: Registration screen can be rendered
    When I am on "/register"
    Then the response status code should be 200

  Scenario: New users can register
    When I send a POST request to "/register" with:
      | field                | value           |
      | name                 | Test User       |
      | email                | test@example.com |
      | password             | password        |
      | password_confirmation | password       |
    Then I should be redirected to "/dashboard"
    And the database "users" contains:
      | field | value            |
      | email | test@example.com |
