Feature: Clients
  Managing client contacts in the CRM

  @clients
  Scenario: User can view client list
    Given I am logged in
    And I am on the clients page
    Then I should see the clients table
    And I should see column headers: Name, Email, Phone, Actions

  @clients
  Scenario: User can create a new client
    Given I am logged in
    And I am on the new client page
    When I fill in the client form with:
      | field | value          |
      | name  | Acme Corp      |
      | email | acme@example.com |
    And I press "Save"
    Then I should see "Acme Corp" in the client list
    And I should see a success message

  @clients
  Scenario: User can edit an existing client
    Given a client "Acme Corp" exists
    And I am logged in
    And I am on the clients page
    When I click "Edit" for client "Acme Corp"
    And I fill in "name" with "Acme Corporation"
    And I press "Save"
    Then I should see "Acme Corporation" in the client list

  @clients
  Scenario: User can delete a client
    Given a client "Old Client" exists
    And I am logged in
    And I am on the clients page
    When I click "Delete" for client "Old Client"
    And I confirm the deletion
    Then I should not see "Old Client" in the client list
