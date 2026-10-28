# Migrating from `sandstorm/neostwofactorauthentication`

`sandstorm/neostwofactorauthentication` is abandoned and continues as `sandstorm/backendusermanagement`.
Functionality for 2FA is unchanged, only the names changed and new features (independent of 2FA) were added.
The 2FA part now lives in its own `SecondFactor` namespace, next to the new `UserManagement`.

| | old | new |
|---|---|---|
| Composer package | `sandstorm/neostwofactorauthentication` | `sandstorm/backendusermanagement` |
| Package key | `Sandstorm.NeosTwoFactorAuthentication` | `Sandstorm.BackendUserManagement` |
| PHP namespace | `Sandstorm\NeosTwoFactorAuthentication\…` | `Sandstorm\BackendUserManagement\SecondFactor\…` |
| Backend module controller | `…\Controller\BackendController` | `…\SecondFactor\Controller\ModuleController` |
| Settings | `Sandstorm.NeosTwoFactorAuthentication.*` | `Sandstorm.BackendUserManagement.secondFactor.*` |
| | `….enforceTwoFactorAuthentication` | `….secondFactor.enforceForAll` |
| | `….enforce2FAForAuthenticationProviders` | `….secondFactor.enforceForAuthenticationProviders` |
| | `….enforce2FAForRoles` | `….secondFactor.enforceForRoles` |
| Fusion prototypes | `…:Component.<Name>` | `…:SecondFactor.Component.<Name>` |
| | `…:Page.LoginSecondFactorPage` / `…:Page.SetupSecondFactorPage` | `…:SecondFactor.Page.LoginPage` / `…:SecondFactor.Page.SetupPage` |
| | `…:Page.AbstractPage`, `…:Page.DefaultPage`, `…:Page.LoginBoxPage`, `…:BodyLayout.Default`, `…:Component.(Login)FlashMessages`, `…:Component.Footer` | unchanged (shared) |
| Fusion root paths | `Sandstorm.NeosTwoFactorAuthentication.BackendController.<action>` | `Sandstorm.BackendUserManagement.SecondFactor.ModuleController.<action>` |
| | `Sandstorm.NeosTwoFactorAuthentication.LoginController.<action>` | `Sandstorm.BackendUserManagement.SecondFactor.LoginController.<action>` |
| Privilege targets | `…:LoginWithSecondFactor`, `…:BackendModule`, `…:PasswordlessLogin` | `…:SecondFactor.Login`, `…:SecondFactor.Module`, `…:SecondFactor.PasswordlessLogin` |
| Translations | `Main.xlf`, `Backend.xlf` | `SecondFactor.xlf` (merged, ids unchanged) |
| Login form scripts | `Neos.Neos.userInterface.backendLoginForm.scripts`: `…:AdditionalScripts` | `…:SecondFactorScripts` |
| 2FA login stylesheets (only loaded on the 2FA screens now, not on the core login) | `Neos.Neos.userInterface.backendLoginForm.stylesheets`: `…:AdditionalStyles`, `…:SharedStyles` | `Sandstorm.BackendUserManagement.secondFactor.loginForm.stylesheets`: `…:SecondFactorStyles`, `…:SecondFactorSharedStyles` |
| Resources | `Public/Styles/Login.css`, `Public/Styles/Backend.css`, `Public/Styles/Shared.css`, `Public/index.js` | `Public/Styles/SecondFactorLogin.css`, `Public/Styles/SecondFactor.css`, `Public/Styles/SecondFactorShared.css`, `Public/JavaScript/totpSecret.js` |
| CSS classes | `neos-two-factor__*`, `neos-2fa-control-group` | `neos-second-factor__*`, `neos-second-factor__control-group` |
| | `neos-two-factor__login`, `neos-two-factor__login-label`, `neos-two-factor__enforced-notice` (shared login box) | `neos-backend-user-management__login`, `…__login-label`, `…__notice` |
| | `neos-two-factor-flashmessage`, `neos-two-factor-flashmessage-<severity>` | `neos-backend-user-management__flashmessage`, `…__flashmessage--<severity>` |
| Second factor table | `sandstorm_neostwofactorauthentication_domain_model_secondfactor` | `sandstorm_backendusermanagement_secondfactor` |

Unchanged: URLs (`/neos/management/twoFactorAuthentication`, `/neos/second-factor-*`) and the remaining setting names
below `secondFactor` (`issuerName`, `totpLeewayInSeconds`, `webAuthn.*`).

## Steps

1. **Swap the composer package** (root `composer.json` and every package `composer.json` requiring it):

   ```bash
   composer remove sandstorm/neostwofactorauthentication --no-update
   composer require sandstorm/backendusermanagement
   ```

   Both packages cannot be installed at the same time (declared as `conflict`).

2. **Adjust your code and package configuration** with the bundled Flow code migration, once per own package:

   ```bash
   ./flow core:migrate <Your.PackageKey>
   ```

   It applies all renames of the table above in `*.php`, `*.yaml`, `*.fusion`, `*.html`, `*.xlf`, `*.js`, `*.ts` and
   `*.css`, including moving the settings below `secondFactor:` (comments are kept). Review the diff. Not covered:
   flow style YAML (`NeosTwoFactorAuthentication: { … }`) and settings nested deeper than `Sandstorm:` → package key.

3. **Adjust global configuration manually** – `core:migrate` does not touch `Configuration/` outside of packages:

   ```yaml
   # before
   Sandstorm:
     NeosTwoFactorAuthentication:
       enforceTwoFactorAuthentication: true
       webAuthn:
         relyingPartyId: 'example.com'

   # after
   Sandstorm:
     BackendUserManagement:
       secondFactor:
         enforceForAll: true
         webAuthn:
           relyingPartyId: 'example.com'
   ```

   If you override the 2FA login stylesheets (`Sandstorm.NeosTwoFactorAuthentication:AdditionalStyles` / `:SharedStyles`
   below `Neos.Neos.userInterface.backendLoginForm.stylesheets`), move them to
   `Sandstorm.BackendUserManagement.secondFactor.loginForm.stylesheets` – in packages too, `core:migrate` only renames
   the keys and prints a warning.

4. **Migrate the database** – renames the second factor table in place, all registered second factors are kept:

   ```bash
   ./flow doctrine:migrate
   ```

   Doctrine migration versions are not bound to the package, so already executed migrations are not run again.

5. **Flush sessions – required.** Sessions contain objects of the old classes (e.g. the passwordless login token);
   without the flush, requests of logged-in users fail with a `TypeError` (`__PHP_Incomplete_Class`):

   ```bash
   ./flow cache:flushone Flow_Session_Storage
   ./flow cache:flushone Flow_Session_MetaData
   ```

6. **Flush caches and publish resources:**

   ```bash
   ./flow flow:cache:flush
   ./flow resource:publish
   ```

## Side effects on deployment

- All backend users are logged out (session flush, see step 5).
- New, on by default: "forgot password" link on the login screen. Configure
  `Sandstorm.BackendUserManagement.userManagement.mail.from.address` and `Neos.SymfonyMailer.mailer.dsn`, or turn it
  off with `Sandstorm.BackendUserManagement.userManagement.enabled: false` (see README "User management").
- Existing users usually have no validated email address and get no "forgot password" mail until it is validated
  (`./flow usermanagement:setemail <username> <email> --validated`); users sharing an address get all their links in one
  mail. See README "Existing users".
