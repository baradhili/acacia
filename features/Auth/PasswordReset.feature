Feature: Password Reset
  Password reset functionality

  @password-reset
  Scenario: User can request password reset
    Given a user with email "user@example.com" exists
    And I am on the login page
    When I click "Forgot your password?"
    And I fill in "email" with "user@example.com"
    And I press "Email Password Reset Link"
    Then I should see "emailed your password reset link"
    And a reset email should be sent to the user

  @password-reset
  Scenario: User can reset password with valid token
    Given a user with email "user@example.com" exists
    And the user has a valid reset token
    When I visit the password reset page with the token
    And I fill in:
      | email               | user@example.com    |
      | password           | NewPassword123      |
      | password_confirmation | NewPassword123    |
    And I press "Reset Password"
    Then I should see "has been reset"
    And I should be able to login with the new password

  @password-reset
  Scenario: Password reset fails with invalid token
    Given a user with email "user@example.com" exists
    And I visit the password reset page with an invalid token
    When I fill in:
      | email               | user@example.com    |
      | password           | NewPassword123      |
      | password_confirmation | NewPassword123    |
    And I press "Reset Password"
    Then I should see "invalid"
    And I should not be able to reset my password

  @password-reset
  Scenario: Password reset requires matching confirmation
    Given a user with email "user@example.com" exists
    And the user has a valid reset token
    When I visit the password reset page with the token
    And I fill in:
      | email               | user@example.com    |
      | password           | NewPassword123      |
      | password_confirmation | DifferentPass456 |
    And I press "Reset Password"
    Then I should see "does not match"
