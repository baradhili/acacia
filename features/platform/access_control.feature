Feature: Access control smoke
  As a visitor
  Protected pages should require authentication

  Scenario: Protected pages redirect guests to the login page
    When I am on "/dashboard"
    Then I should be redirected to "/login"
