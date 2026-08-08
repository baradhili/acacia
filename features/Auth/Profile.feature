Feature: Profile
  User profile management

  @profile
  Scenario: User can view their profile
    Given I am logged in
    When I go to my profile page
    Then I should see my name
    And I should see my email
    And I should see my avatar

  @profile
  Scenario: User can update their name
    Given I am logged in
    And I am on my profile page
    When I fill in "name" with "Updated Name"
    And I press "Save"
    Then I should see "Profile updated"
    And my name should be "Updated Name"

  @profile
  Scenario: User can change their password
    Given I am logged in
    And I am on my profile page
    When I click "Change Password"
    And I fill in:
      | current_password      | OldPassword123      |
      | password              | NewPassword123      |
      | password_confirmation | NewPassword123      |
    And I press "Update Password"
    Then I should see "Password updated"
    And I should be able to login with "NewPassword123"

  @profile
  Scenario: User can upload avatar
    Given I am logged in
    And I am on my profile page
    When I click "Change Avatar"
    And I select an image file
    And I press "Upload"
    Then my avatar should be updated
