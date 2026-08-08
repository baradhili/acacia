Feature: Password Update
  Updating user password

  @password-update
  Scenario: User can update password from profile
    Given I am logged in
    And I am on my profile page
    When I click "Update Password"
    And I fill in:
      | current_password       | CurrentPass123      |
      | password               | NewPassword456     |
      | password_confirmation   | NewPassword456     |
    And I press "Update Password"
    Then I should see "Password updated successfully"
    And I should remain logged in

  @password-update
  Scenario: Password update fails with wrong current password
    Given I am logged in
    And I am on my profile page
    When I click "Update Password"
    And I fill in:
      | current_password       | WrongPassword123    |
      | password               | NewPassword456     |
      | password_confirmation   | NewPassword456     |
    And I press "Update Password"
    Then I should see "Current password is incorrect"

  @password-update
  Scenario: Password update requires confirmation
    Given I am logged in
    And I am on my profile page
    When I click "Update Password"
    And I fill in:
      | current_password       | CurrentPass123      |
      | password               | NewPassword456     |
    And I press "Update Password"
    Then I should see "password_confirmation is required"
