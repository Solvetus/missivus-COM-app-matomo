<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-COM-app-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Missivus;

use Piwik\Piwik;
use Piwik\Plugins\Missivus\Configuration\Settings as MissivusConfig;
use Piwik\Settings\FieldConfig;
use Piwik\Settings\Setting;
use Solvetus\Missivus\Auth\Credentials;

/**
 * The Missivus settings page, under Administration → System → General settings.
 *
 * Two rules run through every field here:
 *
 *  - A value supplied by config.ini.php or the environment wins, and its field is rendered
 *    disabled with an explanatory title. The stored value is never shown.
 *  - A secret field whose key is overridden refuses to write to the option table at all, so a
 *    file-managed credential cannot be silently duplicated into the database.
 */
class SystemSettings extends \Piwik\Settings\Plugin\SystemSettings
{
    /** @var Setting */
    public $enabled;

    /** @var Setting */
    public $tenantId;

    /** @var Setting */
    public $clientId;

    /** @var Setting */
    public $authMethod;

    /** @var Setting */
    public $clientSecret;

    /** @var Setting */
    public $certificatePath;

    /** @var Setting */
    public $certificatePassphrase;

    /** @var Setting */
    public $senderMailbox;

    /** @var Setting */
    public $saveToSentItems;

    /** @var Setting */
    public $fallbackToDefault;

    /** @var Setting */
    public $testEmail;

    protected function init()
    {
        $this->enabled = $this->createEnabledSetting();
        $this->tenantId = $this->createTenantIdSetting();
        $this->clientId = $this->createClientIdSetting();
        $this->authMethod = $this->createAuthMethodSetting();
        $this->clientSecret = $this->createClientSecretSetting();
        $this->certificatePath = $this->createCertificatePathSetting();
        $this->certificatePassphrase = $this->createCertificatePassphraseSetting();
        $this->senderMailbox = $this->createSenderMailboxSetting();
        $this->saveToSentItems = $this->createSaveToSentItemsSetting();
        $this->fallbackToDefault = $this->createFallbackSetting();
        $this->testEmail = $this->createTestEmailSetting();
    }

    private function createEnabledSetting()
    {
        return $this->makeSetting('enabled', false, FieldConfig::TYPE_BOOL, function (FieldConfig $field) {
            $field->title = Piwik::translate('Missivus_SettingEnabledTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
            $field->description = Piwik::translate('Missivus_SettingEnabledDescription');
        });
    }

    private function createTenantIdSetting()
    {
        return $this->makeSetting('tenantId', '', FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = Piwik::translate('Missivus_SettingTenantIdTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->description = Piwik::translate('Missivus_SettingTenantIdDescription');
            // Entra accepts a GUID or a verified domain such as contoso.onmicrosoft.com. The value
            // goes into the token URL path, so nothing else may pass.
            $this->validateFormat(
                $field,
                '/^(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[A-Za-z0-9][A-Za-z0-9.-]{0,252}\.[A-Za-z]{2,63})$/i',
                'Missivus_ValidationTenantId'
            );
            $this->applyOverride($field, MissivusConfig::KEY_TENANT_ID);
        });
    }

    private function createClientIdSetting()
    {
        return $this->makeSetting('clientId', '', FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = Piwik::translate('Missivus_SettingClientIdTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->description = Piwik::translate('Missivus_SettingClientIdDescription');
            $this->validateFormat(
                $field,
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                'Missivus_ValidationClientId'
            );
            $this->applyOverride($field, MissivusConfig::KEY_CLIENT_ID);
        });
    }

    private function createAuthMethodSetting()
    {
        // A client secret is the documented first route: two clicks in Entra and nothing to manage
        // on the filesystem. Certificates are supported as optional hardening, not as the default.
        $default = Credentials::METHOD_SECRET;

        return $this->makeSetting('authMethod', $default, FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = Piwik::translate('Missivus_SettingAuthMethodTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_SINGLE_SELECT;
            $field->availableValues = array(
                Credentials::METHOD_SECRET => Piwik::translate('Missivus_AuthMethodSecret'),
                Credentials::METHOD_CERTIFICATE => Piwik::translate('Missivus_AuthMethodCertificate'),
            );
            $field->description = Piwik::translate('Missivus_SettingAuthMethodDescription');
            $this->applyOverride($field, MissivusConfig::KEY_AUTH_METHOD);
        });
    }

    private function createCertificatePathSetting()
    {
        return $this->makeSetting('certificatePath', '', FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = Piwik::translate('Missivus_SettingCertificatePathTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->description = Piwik::translate('Missivus_SettingCertificatePathDescription');
            $field->condition = 'authMethod == "' . Credentials::METHOD_CERTIFICATE . '"';
            $this->validateEach($field, function ($value) {
                // An absolute path only: a relative one would resolve against whatever directory
                // the current entry point happens to be in, which differs between web and console.
                if (strpos($value, "\0") !== false || !preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $value)) {
                    throw new \Exception(Piwik::translate('Missivus_ValidationCertificatePath'));
                }

                if (!is_readable($value)) {
                    throw new \Exception(Piwik::translate('Missivus_ValidationCertificatePathUnreadable', array($value)));
                }
            });
            $this->applyOverride($field, MissivusConfig::KEY_CERTIFICATE_PATH);
        });
    }

    private function createCertificatePassphraseSetting()
    {
        $setting = $this->makeSetting('certificatePassphrase', '', FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = Piwik::translate('Missivus_SettingCertificatePassphraseTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_PASSWORD;
            $field->description = Piwik::translate('Missivus_SettingCertificatePassphraseDescription');
            $field->condition = 'authMethod == "' . Credentials::METHOD_CERTIFICATE . '"';
            $this->validateCredentialLiteral($field, 'Missivus_ValidationCertificatePassphrase');
            $this->applyOverride($field, MissivusConfig::KEY_CERTIFICATE_PASSPHRASE);
            $this->blockWriteWhenOverridden($field, MissivusConfig::KEY_CERTIFICATE_PASSPHRASE, 'certificatePassphrase');
        });

        return $setting;
    }

    private function createClientSecretSetting()
    {
        return $this->makeSetting('clientSecret', '', FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = Piwik::translate('Missivus_SettingClientSecretTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_PASSWORD;
            $field->description = Piwik::translate('Missivus_SettingClientSecretDescription');
            $field->condition = 'authMethod == "' . Credentials::METHOD_SECRET . '"';
            $this->validateCredentialLiteral($field, 'Missivus_ValidationClientSecret');
            $this->applyOverride($field, MissivusConfig::KEY_CLIENT_SECRET);
            $this->blockWriteWhenOverridden($field, MissivusConfig::KEY_CLIENT_SECRET, 'clientSecret');
        });
    }

    private function createSenderMailboxSetting()
    {
        return $this->makeSetting('senderMailbox', '', FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = Piwik::translate('Missivus_SettingSenderMailboxTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->description = Piwik::translate('Missivus_SettingSenderMailboxDescription');
            $field->inlineHelp = Piwik::translate('Missivus_SettingSenderMailboxHelp');
            $this->validateEach($field, function ($value) {
                // This address becomes /users/{sender} in the Graph URL and the From on every mail.
                if (!Piwik::isValidEmailString($value)) {
                    throw new \Exception(Piwik::translate('Missivus_ValidationSenderMailbox'));
                }
            });
            $this->applyOverride($field, MissivusConfig::KEY_SENDER_MAILBOX);
        });
    }

    private function createSaveToSentItemsSetting()
    {
        return $this->makeSetting('saveToSentItems', false, FieldConfig::TYPE_BOOL, function (FieldConfig $field) {
            $field->title = Piwik::translate('Missivus_SettingSaveToSentItemsTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
            $field->description = Piwik::translate('Missivus_SettingSaveToSentItemsDescription');
        });
    }

    private function createFallbackSetting()
    {
        // Default off, per the brief: a silent fall back to an unconfigured SMTP transport would
        // turn a loud failure into a quiet one.
        return $this->makeSetting('fallbackToDefault', false, FieldConfig::TYPE_BOOL, function (FieldConfig $field) {
            $field->title = Piwik::translate('Missivus_SettingFallbackTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
            $field->description = Piwik::translate('Missivus_SettingFallbackDescription');
        });
    }

    /**
     * Not a setting anyone stores — SystemSettings has no button primitive, so this field exists
     * purely to host the Vue component that calls Missivus.sendTestEmail.
     */
    private function createTestEmailSetting()
    {
        return $this->makeSetting('testEmail', '', FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = Piwik::translate('Missivus_SettingTestEmailTitle');
            $field->description = Piwik::translate('Missivus_SettingTestEmailDescription');
            $field->customFieldComponent = array(
                'plugin' => 'Missivus',
                'name' => 'SendTestEmail',
            );
        });
    }

    /**
     * Validate a field, but only once it has something in it.
     *
     * Every field here is legitimately empty until the operator has been through the Entra steps,
     * and the plugin's master switch defaults to off precisely so a half-filled page is a valid
     * state. So emptiness is never the error; a value that could not possibly be right is.
     *
     * @param FieldConfig $field
     * @param callable    $check Receives the trimmed value, throws on rejection.
     * @return void
     */
    private function validateEach(FieldConfig $field, $check)
    {
        $field->validate = function ($value) use ($check) {
            $value = trim((string) $value);

            if ($value === '') {
                return;
            }

            $check($value);
        };
    }

    /**
     * @param FieldConfig $field
     * @param string      $pattern
     * @param string      $messageKey
     * @return void
     */
    private function validateFormat(FieldConfig $field, $pattern, $messageKey)
    {
        $this->validateEach($field, function ($value) use ($pattern, $messageKey) {
            if (!preg_match($pattern, $value)) {
                throw new \Exception(Piwik::translate($messageKey));
            }
        });
    }

    /**
     * A secret is opaque, so there is no format to check — but a value carrying whitespace, a
     * control character or a newline is a copy-and-paste accident every time, and diagnosing it
     * later is miserable because nothing may ever print the value back.
     *
     * @param FieldConfig $field
     * @param string      $messageKey
     * @return void
     */
    private function validateCredentialLiteral(FieldConfig $field, $messageKey)
    {
        $this->validateEach($field, function ($value) use ($messageKey) {
            if (strlen($value) > 1024 || preg_match('/[\s\x00-\x1f\x7f]/', $value)) {
                throw new \Exception(Piwik::translate($messageKey));
            }
        });
    }

    /**
     * Show, but do not offer to edit, a value that config.ini.php or the environment already owns.
     *
     * @param FieldConfig $field
     * @param string      $key
     * @return void
     */
    private function applyOverride(FieldConfig $field, $key)
    {
        if (!MissivusConfig::hasOverride($key)) {
            return;
        }

        $field->uiControlAttributes['disabled'] = 'disabled';
        $field->title .= ' — ' . Piwik::translate('Missivus_SetInConfigFile');
        $field->description = Piwik::translate('Missivus_SetInConfigFileDescription', array($key));
    }

    /**
     * Make a save from the UI a no-op for a credential that lives outside the database, so the
     * secret is never duplicated into the option table.
     *
     * @param FieldConfig $field
     * @param string      $key
     * @param string      $settingName
     * @return void
     */
    private function blockWriteWhenOverridden(FieldConfig $field, $key, $settingName)
    {
        if (!MissivusConfig::hasOverride($key)) {
            return;
        }

        $settings = $this;

        $field->transform = function ($value) use ($settings, $settingName) {
            return $settings->{$settingName}->getValue();
        };
    }
}
