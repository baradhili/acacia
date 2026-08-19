Feature: Password management
  In order to keep my account secure
  As a user
  I need password reset and update flows

  Scenario: Forgot password screen can be rendered
    When I am on "/forgot-password"
    Then the response status code should be 200

  Scenario: Reset password link can be requested
    Given notifications are faked
    And a user "user@example.com" exists with password "password"
    When I request a password reset link for "user@example.com"
    Then a password reset notification should have been sent to "user@example.com"

  Scenario: Reset password screen can be rendered
    Given notifications are faked
    And a user "user@example.com" exists with password "password"
    When I request a password reset link for "user@example.com"
    And I open the reset password page from the link sent to "user@example.com"
    Then the response status code should be 200

  Scenario: Password can be reset with a valid token
    Given notifications are faked
    And a user "user@example.com" exists with password "password"
    When I request a password reset link for "user@example.com"
    And I reset the password for "user@example.com" to "new-password" using the sent token
    Then I should be redirected to "/login"
    And the stored password for "user@example.com" should be "new-password"

  Scenario: Password can be updated
    Given I am logged in as "user@example.com" with password "password"
    When I update my password from "password" to "new-password"
    Then I should be redirected to "/profile"
    And the stored password for "user@example.com" should be "new-password"

  Scenario: Correct current password must be provided to update password
    Given I am logged in as "user@example.com" with password "password"
    When I update my password from "wrong-password" to "new-password"
    Then I should be redirected to "/profile"
    And the validation bag "updatePassword" should fail on "current_password"
    And the stored password for "user@example.com" should be "password"
