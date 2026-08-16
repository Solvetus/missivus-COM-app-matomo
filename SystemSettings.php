<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-COM-app-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Missivus;

use Piwik\Piwik;
use Piwik\Plugins\Missivus\Config\Settings as MissivusConfig;
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
        $this->certificatePath = $this->createCertificatePathSetting();
        $this->certificatePassphrase = $this->createCertificatePassphraseSetting();
        $this->clientSecret = $this->createClientSecretSetting();
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
            $this->applyOverride($field, MissivusConfig::KEY_TENANT_ID);
        });
    }

    private function createClientIdSetting()
    {
        return $this->makeSetting('clientId', '', FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = Piwik::translate('Missivus_SettingClientIdTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->description = Piwik::translate('Missivus_SettingClientIdDescription');
            $this->applyOverride($field, MissivusConfig::KEY_CLIENT_ID);
        });
    }

    private function createAuthMethodSetting()
    {
        $default = Credentials::METHOD_CERTIFICATE;

        return $this->makeSetting('authMethod', $default, FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = Piwik::translate('Missivus_SettingAuthMethodTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_SINGLE_SELECT;
            $field->availableValues = array(
                Credentials::METHOD_CERTIFICATE => Piwik::translate('Missivus_AuthMethodCertificate'),
                Credentials::METHOD_SECRET => Piwik::translate('Missivus_AuthMethodSecret'),
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
