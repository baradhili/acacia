Feature: Password Update
  Updating user password

  @password-update
  Scenario: User can update password from profile
    Given I am logged in with password "password"
    And I am on my profile page
    When I fill in:
      | current_password       | password           |
      | password               | NewPassword456     |
      | password_confirmation  | NewPassword456     |
    And I press "Save"
    Then I should see "Saved"
    And I should remain logged in

  @password-update
  Scenario: Password update fails with wrong current password
    Given I am logged in
    And I am on my profile page
    When I fill in:
      | current_password       | WrongPassword123    |
      | password               | NewPassword456      |
      | password_confirmation  | NewPassword456      |
    And I press "Save"
    Then I should see "password is incorrect"

  @password-update
  Scenario: Password update requires confirmation
    Given I am logged in
    And I am on my profile page
    When I fill in:
      | current_password       | password            |
      | password               | NewPassword456      |
    And I press "Save"
    Then I should see "confirmation does not match"
