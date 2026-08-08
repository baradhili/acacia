Feature: Email Verification
  Email verification functionality

  @email-verification
  Scenario: New user receives verification email
    Given I register with email "newuser@example.com"
    Then I should receive a verification email at "newuser@example.com"
    And the email should contain a verification link

  @email-verification
  Scenario: User can verify email with valid token
    Given I have a pending verification email
    When I click the verification link in the email
    Then my email should be verified
    And I should see "Email verified successfully"

  @email-verification
  Scenario: User cannot verify with invalid token
    When I visit the verification page with an invalid token
    Then I should see "Invalid verification link"
    And my email should remain unverified

  @email-verification
  Scenario: User can resend verification email
    Given I registered but haven't verified my email
    And I am on the verification required page
    When I click "Resend Verification Email"
    Then I should receive another verification email

  @email-verification
  Scenario: Unverified user sees verification notice
    Given I registered but haven't verified my email
    When I try to access protected pages
    Then I should be redirected to the verification notice page
    And I should see "Please verify your email"
