# E2E Tests

[Playwright](https://playwright.dev) + [playwright-bdd](https://vitalets.github.io/playwright-bdd/) (Gherkin) against
a Dockerised Neos (the *system under test*, SUT):

- **Neos 8**: PHP 8.3, MariaDB 10.11
- **Neos 9**: PHP 8.5, MariaDB 11.4

The SUT is built with the package root as Docker build context and installs it via a Composer path repository, so
every run tests the current working tree.

## Setup

Requires Docker, make and the Node.js version from `.nvmrc`.

```bash
cd Tests/E2E
make setup        # build SUT images, npm install, install Chromium, generate BDD files
make setup-test   # only the node part
```

## Running tests

```bash
make test         # everything, Neos 8 + 9
make test-neos8   # all variants on Neos 8
make test-neos9   # all variants on Neos 9
make help         # all targets
```

Playwright starts and stops the containers. The first run takes a few minutes (migrations, site import).

### Variants

Each variant boots the SUT in its own `FLOW_CONTEXT` and runs only scenarios with the matching tag. Make target:
`make test-neos8-<variant>` / `make test-neos9-<variant>`.

| Variant                    | Tag                             | `FLOW_CONTEXT` (below `Production/E2E-SUT`) | Configuration                                          |
| -------------------------- | ------------------------------- | ------------------------------------------- | ------------------------------------------------------ |
| `defaults`                 | `@default-context`              | –                                           | package defaults (2FA optional, user management on)    |
| `enforce-all`              | `@enforce-for-all`              | `/EnforceForAll`                            | `secondFactor.enforceForAll: true`                     |
| `enforce-role`             | `@enforce-for-role`             | `/EnforceForRole`                           | `secondFactor.enforceForRoles`                         |
| `enforce-provider`         | `@enforce-for-provider`         | `/EnforceForProvider`                       | `secondFactor.enforceForAuthenticationProviders`       |
| `passwordless`             | `@passwordless`                 | `/Passwordless`                             | `secondFactor.webAuthn.passwordlessLoginEnabled: true` |
| `enforce-all-passwordless` | `@enforce-for-all-passwordless` | `/EnforceForAllPasswordless`                | enforce for all + passwordless                         |
| `user-management`          | `@user-management`              | `/UserManagement`                           | user management module + mail settings                 |
| `username-is-email`        | `@username-is-email`            | `/UserManagement/UsernameIsEmail`           | `user-management` + `usernameIsEmail: true`            |

Contexts live in `system_under_test/sut_file_system_overrides/app/Configuration/Production/E2E-SUT/`. The npm scripts
in `package.json` set `SUT` (`neos8`/`neos9`), `FLOW_CONTEXT` and `--grep <tag>`.

New variant: add a context folder, tag the scenarios, add npm scripts and make targets (and add them to
`test-neos8`/`test-neos9`).

### Debugging

Any Playwright flag works after `--`:

```bash
npm run test:neos8:enforce-all -- --ui       # Playwright UI
npm run test:neos8:enforce-all -- --debug    # Playwright Inspector
npm run test:neos8:enforce-all -- --headed
npm run test:neos8:enforce-all -- --grep @debug   # after tagging a scenario with @debug
```

Make targets pass them via `ARGS`:

```bash
make test-neos9-user-management ARGS="--last-failed"                 # rerun only the failed tests
make test-neos9-user-management ARGS='-g "Requests are throttled"'  # one scenario by title
```

```bash
make start-sut-neos8   # start without tests (also -neos9)
make log-sut-neos8     # follow logs
make enter-sut-neos8   # shell in the Neos container
make stop-sut-neos8    # stop, keep volumes
make sut-prune         # stop all, delete volumes
```

Mails sent by the SUT are caught by Mailpit: http://localhost:8025.

## Structure

```
Tests/E2E/
├── features/            # .feature files, one folder per variant
├── steps/               # step definitions; hooks.ts = cleanup
├── helpers/
│   ├── general-pages.ts # page objects (login, …)
│   ├── 2fa-pages.ts     # page objects for 2FA flows
│   ├── system.ts        # Flow CLI via docker exec (users, emails, sessions, throttle)
│   ├── totp.ts          # TOTP codes (otplib)
│   ├── webauthn.ts      # CDP virtual authenticator
│   ├── mailpit.ts       # read mails via the Mailpit API
│   └── state.ts         # state shared between steps of a scenario
└── system_under_test/   # Dockerfile, neos8/, neos9/, config overrides
```

## Writing tests

1. Add a `.feature` file under `features/<variant>/`, tagged with the variant's tag.
2. Reuse steps from `steps/`; add missing ones (matched by exact string, available in all features).
3. Put page interaction into page objects in `helpers/`.
4. `make generate-bdd-files` (also done by every test run). Generated specs (`.features-gen/`) are not committed.

```gherkin
@default-context
Feature: My feature

  Background:
    Given A user with username "admin", password "password" and role "Neos.Neos:Administrator" exists

  Scenario: Admin can do the thing
    When I log in with username "admin" and password "password"
    Then I should see the expected result
```

### Conventions

- **Isolation**: tests run sequentially (`workers: 1`) against one SUT and database. After each scenario,
  `steps/hooks.ts` logs out and deletes all users (cascades to their 2FA devices). User management scenarios also
  clear Mailpit and the forgot-password throttle before they start. Add cleanup there if you create other data.
- **Setup via CLI, behaviour via UI**: users are created with `./flow user:create` (`helpers/system.ts`); second
  factors are enrolled through the UI, like a real user would.
- **Selectors**: prefer `getByRole(role, { name })` (the SUT renders English). Use `data-test-id` only where the
  accessible name is ambiguous (e.g. the delete confirmation button).
- **Adding a second factor** starts on a method picker (`Authenticator app` / `Security key (Yubikey / WebAuthn)`),
  both in the module and the enforced setup at `/neos/second-factor-setup`; the page objects in
  `helpers/2fa-pages.ts` handle it.
- **TOTP**: `state.deviceNameSecretMap` keeps device name → secret, so later steps can generate codes.
- **WebAuthn**: `Given I have a virtual security key` installs a CDP virtual authenticator on the browser context
  (Chromium only). It auto-approves with user verification and survives logout/login within a scenario.
- **Mails**: `helpers/mailpit.ts` (`waitForMailsTo`) reads mails; links from them are kept in `state.lastLink`.
