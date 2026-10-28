<?php

declare(strict_types=1);

namespace Neos\Flow\Core\Migrations;

/**
 * Rename Sandstorm.NeosTwoFactorAuthentication to Sandstorm.BackendUserManagement
 *
 * The 2FA part now lives in the "SecondFactor" sub namespace / Fusion prefix / settings path. Adjusts PHP class names,
 * settings (incl. the new nesting below "secondFactor"), Fusion prototypes and root paths, policy privilege targets,
 * translation sources and resource paths. composer.json and global Configuration/ (outside of packages) have to be
 * adjusted manually, see docs/MIGRATION.md.
 */
class Version20261028120100 extends AbstractMigration
{
    private const OLD = 'Sandstorm.NeosTwoFactorAuthentication';
    private const NEW = 'Sandstorm.BackendUserManagement';

    /**
     * renamed 2FA settings (below the new "secondFactor" key)
     */
    private const SETTING_RENAMES = [
        'enforceTwoFactorAuthentication' => 'enforceForAll',
        'enforce2FAForAuthenticationProviders' => 'enforceForAuthenticationProviders',
        'enforce2FAForRoles' => 'enforceForRoles',
    ];

    /**
     * Fusion prototypes of the 2FA part, now prefixed with "SecondFactor."
     */
    private const SECOND_FACTOR_COMPONENTS = [
        'CancelLoginButton', 'LoginSecondFactorStep', 'LoginWebAuthnStep', 'MethodPicker', 'PasswordlessLoginButton',
        'RegisterPasskeyBanner', 'SecondFactorList', 'SetupTotpStep', 'SetupWebAuthnStep',
    ];

    public function getIdentifier(): string
    {
        return 'Sandstorm.BackendUserManagement-20261028120100';
    }

    public function up(): void
    {
        $fileTypes = ['php', 'yaml', 'fusion', 'html', 'xlf', 'js', 'ts', 'css'];
        $old = preg_quote(self::OLD, '/');

        // settings: "Sandstorm: NeosTwoFactorAuthentication: ..." -> "Sandstorm: BackendUserManagement: secondFactor: ..."
        // (text based instead of moveSettingsPaths() to keep YAML comments)
        $this->searchAndReplaceRegex(
            '/\A.*\z/s',
            fn (array $matches) => self::nestSecondFactorSettings($matches[0], 'NeosTwoFactorAuthentication'),
            ['yaml']
        );

        // PHP: all classes of the old package belong to the 2FA part; an import of the renamed controller keeps its
        // short name via an alias, so usages like BackendController::class keep working
        $this->searchAndReplaceRegex(
            '/^(\s*use\s+\\\\?)Sandstorm\\\\NeosTwoFactorAuthentication\\\\Controller\\\\BackendController\s*;/m',
            '$1Sandstorm\\BackendUserManagement\\SecondFactor\\Controller\\ModuleController as BackendController;',
            ['php']
        );
        $this->searchAndReplaceRegex(
            '/Sandstorm(\\\\+)NeosTwoFactorAuthentication\1Controller\1BackendController\b/',
            'Sandstorm$1BackendUserManagement$1SecondFactor$1Controller$1ModuleController',
            $fileTypes
        );
        $this->searchAndReplaceRegex(
            '/Sandstorm(\\\\+)NeosTwoFactorAuthentication(?=\\\\)/',
            'Sandstorm$1BackendUserManagement$1SecondFactor',
            $fileTypes
        );

        // settings paths in strings, e.g. Configuration.setting('Sandstorm.NeosTwoFactorAuthentication.webAuthn.timeout')
        foreach (self::SETTING_RENAMES as $oldSetting => $newSetting) {
            $this->searchAndReplaceRegex('/' . $old . '\.' . $oldSetting . '\b/', self::NEW . '.secondFactor.' . $newSetting, $fileTypes);
        }
        $this->searchAndReplaceRegex('/' . $old . '\.(?=[a-z])/', self::NEW . '.secondFactor.', $fileTypes);

        // Fusion root paths of the controllers
        $this->searchAndReplace(self::OLD . '.BackendController.', self::NEW . '.SecondFactor.ModuleController.', $fileTypes);
        $this->searchAndReplace(self::OLD . '.LoginController.', self::NEW . '.SecondFactor.LoginController.', $fileTypes);

        // Fusion prototypes
        $this->searchAndReplaceRegex(
            '/' . $old . ':Component\.(' . implode('|', self::SECOND_FACTOR_COMPONENTS) . ')\b/',
            self::NEW . ':SecondFactor.Component.$1',
            $fileTypes
        );
        $this->searchAndReplace(self::OLD . ':Page.LoginSecondFactorPage', self::NEW . ':SecondFactor.Page.LoginPage', $fileTypes);
        $this->searchAndReplace(self::OLD . ':Page.SetupSecondFactorPage', self::NEW . ':SecondFactor.Page.SetupPage', $fileTypes);

        // policy privilege targets
        $this->searchAndReplaceRegex('/' . $old . ':LoginWithSecondFactor\b/', self::NEW . ':SecondFactor.Login', $fileTypes);
        $this->searchAndReplaceRegex('/' . $old . ':BackendModule\b/', self::NEW . ':SecondFactor.Module', $fileTypes);
        $this->searchAndReplaceRegex('/' . $old . ':PasswordlessLogin\b/', self::NEW . ':SecondFactor.PasswordlessLogin', $fileTypes);

        // login form resources (Neos.Neos.userInterface.backendLoginForm)
        $this->searchAndReplaceRegex('/' . $old . ':AdditionalStyles\b/', self::NEW . ':SecondFactorStyles', $fileTypes);
        $this->searchAndReplaceRegex('/' . $old . ':AdditionalScripts\b/', self::NEW . ':SecondFactorScripts', $fileTypes);
        $this->searchAndReplaceRegex('/' . $old . ':SharedStyles\b/', self::NEW . ':SecondFactorSharedStyles', $fileTypes);
        // the 2FA login stylesheets moved to Sandstorm.BackendUserManagement.secondFactor.loginForm.stylesheets, so
        // overrides below Neos.Neos.userInterface.backendLoginForm.stylesheets have to be moved by hand
        foreach (glob($this->targetPackageData['path'] . '/Configuration/{,*/,*/*/}Settings*.yaml', GLOB_BRACE) ?: [] as $settingsFile) {
            if (preg_match('/' . $old . ':(AdditionalStyles|SharedStyles)\b/', (string)file_get_contents($settingsFile))) {
                $this->showWarning(sprintf(
                    '%s overrides a 2FA login stylesheet below Neos.Neos.userInterface.backendLoginForm.stylesheets. Move it to Sandstorm.BackendUserManagement.secondFactor.loginForm.stylesheets.',
                    $settingsFile
                ));
            }
        }

        // translations: Main.xlf and Backend.xlf were merged into SecondFactor.xlf
        $this->searchAndReplaceRegex('/' . $old . ':(Main|Backend):/', self::NEW . ':SecondFactor:', $fileTypes);
        $this->searchAndReplaceRegex(
            '/package\((["\'])' . $old . '\1\)(\.source\((["\'])(Main|Backend)\3\))?(?!\.source\()/',
            'package($1' . self::NEW . '$1).source($1SecondFactor$1)',
            $fileTypes
        );
        // positional arguments, e.g. translateById($id, [], null, null, 'Main', 'Sandstorm.NeosTwoFactorAuthentication')
        $this->searchAndReplaceRegex(
            '/(["\'])(Main|Backend)\1(\s*,\s*)(["\'])' . $old . '\4/',
            '$1SecondFactor$1$3$4' . self::NEW . '$4',
            ['php', 'fusion']
        );
        $this->searchAndReplaceRegex(
            '/package="' . $old . '"(\s+source="(Main|Backend)")?(?!\s+source=)/',
            'package="' . self::NEW . '" source="SecondFactor"',
            ['html']
        );

        // resources
        $this->searchAndReplace('resource://' . self::OLD . '/Public/Styles/Backend.css', 'resource://' . self::NEW . '/Public/Styles/SecondFactor.css', $fileTypes);
        $this->searchAndReplace('resource://' . self::OLD . '/Public/Styles/Login.css', 'resource://' . self::NEW . '/Public/Styles/SecondFactorLogin.css', $fileTypes);
        $this->searchAndReplace('resource://' . self::OLD . '/Public/Styles/Shared.css', 'resource://' . self::NEW . '/Public/Styles/SecondFactorShared.css', $fileTypes);
        $this->searchAndReplace('resource://' . self::OLD . '/Public/index.js', 'resource://' . self::NEW . '/Public/JavaScript/totpSecret.js', $fileTypes);

        // CSS classes: shared login box classes -> "neos-backend-user-management__", 2FA classes -> "neos-second-factor__"
        $this->searchAndReplaceRegex('/neos-two-factor-flashmessage-(?=[a-z\'"])/', 'neos-backend-user-management__flashmessage--', $fileTypes);
        $this->searchAndReplaceRegex('/neos-two-factor-flashmessage\b/', 'neos-backend-user-management__flashmessage', $fileTypes);
        $this->searchAndReplaceRegex('/neos-two-factor__login-label\b/', 'neos-backend-user-management__login-label', $fileTypes);
        $this->searchAndReplaceRegex('/neos-two-factor__login\b(?![-_])/', 'neos-backend-user-management__login', $fileTypes);
        $this->searchAndReplaceRegex('/neos-two-factor__enforced-notice\b/', 'neos-backend-user-management__notice', $fileTypes);
        $this->searchAndReplaceRegex('/neos-2fa-control-group\b/', 'neos-second-factor__control-group', $fileTypes);
        $this->searchAndReplace('neos-two-factor__', 'neos-second-factor__', $fileTypes);

        // everything else: package key
        $this->searchAndReplace(self::OLD, self::NEW, $fileTypes);
        $this->searchAndReplace('Sandstorm/NeosTwoFactorAuthentication', 'Sandstorm/BackendUserManagement', $fileTypes);
    }

    /**
     * Moves all settings below "Sandstorm: <$parentKey>:" (except "userManagement" and "secondFactor") one level deeper
     * below "secondFactor:", renames the parent key to "BackendUserManagement" and the renamed 2FA settings.
     * Works on the text to keep comments; flow style YAML ("{...}") is not supported.
     */
    public static function nestSecondFactorSettings(string $yaml, string $parentKey): string
    {
        $lines = explode("\n", $yaml);
        $result = [];
        $lineCount = count($lines);
        for ($i = 0; $i < $lineCount; $i++) {
            $line = $lines[$i];
            if (
                !preg_match('/^([ \t]+)' . preg_quote($parentKey, '/') . ':[ \t]*(#.*)?$/', $line, $parentMatch)
                || !self::isBelowTopLevelSandstormKey($lines, $i, strlen($parentMatch[1]))
            ) {
                $result[] = $line;
                continue;
            }
            $parentIndent = strlen($parentMatch[1]);

            // the block: all following lines indented deeper than the parent (or empty)
            $block = [];
            for ($j = $i + 1; $j < $lineCount; $j++) {
                if (trim($lines[$j]) !== '' && self::indentOf($lines[$j]) <= $parentIndent) {
                    break;
                }
                $block[] = $lines[$j];
            }
            $trailingEmptyLines = [];
            while ($block !== [] && trim(end($block)) === '') {
                array_unshift($trailingEmptyLines, array_pop($block));
            }
            $i = $j - 1;

            $childIndent = null;
            foreach ($block as $blockLine) {
                if (trim($blockLine) !== '' && !str_starts_with(ltrim($blockLine), '#')) {
                    $childIndent = self::indentOf($blockLine);
                    break;
                }
            }
            $result[] = str_replace($parentKey . ':', 'BackendUserManagement:', $line);
            if ($childIndent === null) {
                array_push($result, ...$block, ...$trailingEmptyLines);
                continue;
            }

            // split into one chunk per child key; comments directly above a key belong to it
            $chunks = [];
            $pending = [];
            foreach ($block as $blockLine) {
                $isChildKey = self::indentOf($blockLine) === $childIndent && trim($blockLine) !== '' && !str_starts_with(ltrim($blockLine), '#');
                $isChildComment = self::indentOf($blockLine) === $childIndent && str_starts_with(ltrim($blockLine), '#');
                if ($isChildKey) {
                    preg_match('/^\s*([^:\s]+)/', $blockLine, $keyMatch);
                    $chunks[] = ['key' => $keyMatch[1], 'lines' => [...$pending, $blockLine]];
                    $pending = [];
                } elseif ($isChildComment || $chunks === []) {
                    $pending[] = $blockLine;
                } else {
                    $chunks[array_key_last($chunks)]['lines'] = [...$chunks[array_key_last($chunks)]['lines'], ...$pending, $blockLine];
                    $pending = [];
                }
            }

            $indentUnit = str_repeat(' ', $childIndent - $parentIndent);
            $secondFactorLines = [];
            $secondFactorPosition = null;
            $output = [];
            foreach ($chunks as $chunk) {
                if (in_array($chunk['key'], ['userManagement', 'secondFactor'], true)) {
                    array_push($output, ...$chunk['lines']);
                    continue;
                }
                $secondFactorPosition ??= count($output);
                foreach ($chunk['lines'] as $chunkLine) {
                    foreach (self::SETTING_RENAMES as $oldSetting => $newSetting) {
                        $chunkLine = preg_replace('/^(\s{' . $childIndent . '})' . $oldSetting . '\s*:/', '$1' . $newSetting . ':', $chunkLine);
                    }
                    $secondFactorLines[] = trim($chunkLine) === '' ? $chunkLine : $indentUnit . $chunkLine;
                }
            }
            if ($secondFactorPosition !== null) {
                array_splice($output, $secondFactorPosition, 0, [str_repeat(' ', $childIndent) . 'secondFactor:', ...$secondFactorLines]);
            }
            array_push($result, ...$output, ...$pending, ...$trailingEmptyLines);
        }
        return implode("\n", $result);
    }

    private static function isBelowTopLevelSandstormKey(array $lines, int $lineNumber, int $indent): bool
    {
        for ($i = $lineNumber - 1; $i >= 0; $i--) {
            $line = $lines[$i];
            if (trim($line) === '' || str_starts_with(ltrim($line), '#') || self::indentOf($line) >= $indent) {
                continue;
            }
            return (bool)preg_match('/^Sandstorm:[ \t]*(#.*)?$/', $line);
        }
        return false;
    }

    private static function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, " \t"));
    }
}
