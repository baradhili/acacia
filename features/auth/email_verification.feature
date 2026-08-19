Feature: Email verification
  In order to confirm my email address
  As a registered user
  I need email verification flows

  Scenario: Email verification screen can be rendered
    Given an unverified user "user@example.com" exists with password "password"
    And I am logged in as "user@example.com" with password "password"
    When I am on "/verify-email"
    Then the response status code should be 200

  Scenario: Email can be verified
    Given an unverified user "user@example.com" exists with password "password"
    And I am logged in as "user@example.com" with password "password"
    And a signed verification link exists for "user@example.com"
    When I visit the verification link
    Then "user@example.com" should be verified

  Scenario: Email is not verified with an invalid hash
    Given an unverified user "user@example.com" exists with password "password"
    And I am logged in as "user@example.com" with password "password"
    And a signed verification link with an invalid hash exists for "user@example.com"
    When I visit the verification link
    Then "user@example.com" should not be verified
