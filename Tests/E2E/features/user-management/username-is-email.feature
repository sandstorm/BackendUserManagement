@username-is-email
Feature: With usernameIsEmail, the username follows a changed email once it is validated

  Background:
    Given A user with username "admin", password "password" and role "Neos.Neos:Administrator" exists
    And user "admin" has the validated email "admin@example.com"
    And A user with username "jane@example.com", password "password" and role "Neos.Neos:Editor" exists
    And user "jane@example.com" has the validated email "jane@example.com"

  Scenario: The username changes when the new email is confirmed - not before
    When I log in with username "admin" and password "password"
    And I open the user "jane@example.com" in the user management module
    And I change the email to "jane.doe@example.com"
    Then the user list should contain "jane@example.com"
    When I log out
    And I open the email confirmation link from the mail to "jane.doe@example.com"
    And I confirm my email address
    Then I should see that my email address is confirmed
    # the confirmation mail and the mail with the new username
    And "jane.doe@example.com" should receive 2 mails
    And the personal workspace of "jane@example.com" should be moved to "jane.doe@example.com"
    When I log in with username "jane@example.com" and password "password"
    Then I should see the login page
    When I log in with username "jane.doe@example.com" and password "password"
    Then I should see the Neos content page

  Scenario: The username is not changed when another account already has it
    Given A user with username "taken@example.com", password "password" and role "Neos.Neos:Editor" exists
    When I log in with username "admin" and password "password"
    And I open the user "jane@example.com" in the user management module
    And I change the email to "taken@example.com"
    And I click "mark-email-validated" in the user details
    Then the user list should contain "jane@example.com"
    And the user list should contain "taken@example.com"

  Scenario: Marking the changed email as validated changes the username right away
    When I log in with username "admin" and password "password"
    And I open the user "jane@example.com" in the user management module
    And I change the email to "jane.doe@example.com" and mark it as validated
    Then the user list should contain "jane.doe@example.com"
    # only the mail with the new username, no confirmation mail
    And "jane.doe@example.com" should receive 1 mail

  Scenario: An admin changes a legacy username to the email
    Given A user with username "jane", password "password" and role "Neos.Neos:Editor" exists
    And user "jane" has the validated email "jane.legacy@example.com"
    When I log in with username "admin" and password "password"
    And I open the user "jane" in the user management module
    And I click "use-email-as-username" in the user details
    Then the user list should contain "jane.legacy@example.com"
    And "jane.legacy@example.com" should receive 1 mail
    When I log out
    And I log in with username "jane.legacy@example.com" and password "password"
    Then I should see the Neos content page

  Scenario: Legacy usernames are changed to the validated email via CLI
    Given A user with username "jane", password "password" and role "Neos.Neos:Editor" exists
    And user "jane" has the validated email "jane.legacy@example.com"
    And A user with username "john", password "password" and role "Neos.Neos:Editor" exists
    And user "john" has the unvalidated email "john@example.com"
    When the usernames are changed to the email via CLI
    Then "jane.legacy@example.com" should receive 1 mail
    # all users: the admin from the background has a validated email, too
    And "admin@example.com" should receive 1 mail
    When I log in with username "admin@example.com" and password "password"
    Then the user list should contain "jane.legacy@example.com"
    # unvalidated email - skipped
    And the user list should contain "john"
