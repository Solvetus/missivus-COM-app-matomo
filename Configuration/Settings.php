<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-COM-app-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Missivus\Configuration;

use Piwik\Config as MatomoConfig;
use Piwik\Plugins\Missivus\SystemSettings;
use Solvetus\Missivus\Auth\ClientAssertion;
use Solvetus\Missivus\Auth\Credentials;

/**
 * The single place that decides what the effective configuration is.
 *
 * Precedence, highest first:
 *
 *   1. environment variable  MISSIVUS_<KEY>
 *   2. config.ini.php        [Missivus] <key>
 *   3. the SystemSettings value in the database
 *
 * A credential that lives in a file or the environment must never be copied into the option table,
 * so SystemSettings asks this class whether a key is overridden and refuses the write if it is.
 */
class Settings implements ConfigurationInterface
{
    const CONFIG_SECTION = 'Missivus';
    const ENV_PREFIX = 'MISSIVUS_';

    const KEY_TENANT_ID = 'tenant_id';
    const KEY_CLIENT_ID = 'client_id';
    const KEY_AUTH_METHOD = 'auth_method';
    const KEY_CLIENT_SECRET = 'client_secret';
    const KEY_CERTIFICATE_PATH = 'certificate_path';
    const KEY_CERTIFICATE_PASSPHRASE = 'certificate_passphrase';
    const KEY_CERTIFICATE_ALGORITHM = 'certificate_algorithm';
    const KEY_SENDER_MAILBOX = 'sender_mailbox';
    const KEY_GRAPH_BASE_URL = 'graph_base_url';
    const KEY_LOGIN_BASE_URL = 'login_base_url';

    /** @var SystemSettings */
    private $settings;

    /**
     * @param SystemSettings $settings
     */
    public function __construct(SystemSettings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Is this key supplied outside the database? If so the settings UI shows it read-only and
     * refuses to store a value for it.
     *
     * @param string $key One of the KEY_* constants.
     * @return bool
     */
    public static function hasOverride($key)
    {
        return self::readOverride($key) !== null;
    }

    /**
     * @param string $key
     * @return string|null
     */
    public static function readOverride($key)
    {
        $environment = getenv(self::ENV_PREFIX . strtoupper($key));

        if (is_string($environment) && trim($environment) !== '') {
            return trim($environment);
        }

        $section = self::configSection();

        if (isset($section[$key]) && trim((string) $section[$key]) !== '') {
            return trim((string) $section[$key]);
        }

        return null;
    }

    /**
     * @return array
     */
    private static function configSection()
    {
        try {
            $section = MatomoConfig::getInstance()->{self::CONFIG_SECTION};
        } catch (\Exception $e) {
            return array();
        }

        return is_array($section) ? $section : array();
    }

    /**
     * @param string $key             One of the KEY_* constants.
     * @param string $settingName     The matching SystemSettings property, or '' when config-only.
     * @param string $default
     * @return string
     */
    private function resolve($key, $settingName = '', $default = '')
    {
        $override = self::readOverride($key);

        if ($override !== null) {
            return $override;
        }

        if ($settingName !== '' && isset($this->settings->{$settingName})) {
            $value = $this->settings->{$settingName}->getValue();

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $default;
    }

    /**
     * @return bool
     */
    public function isEnabled()
    {
        return (bool) $this->settings->enabled->getValue();
    }

    /**
     * @return bool
     */
    public function shouldFallBackToDefaultTransport()
    {
        return (bool) $this->settings->fallbackToDefault->getValue();
    }

    /**
     * @return bool
     */
    public function shouldSaveToSentItems()
    {
        return (bool) $this->settings->saveToSentItems->getValue();
    }

    /**
     * @return string
     */
    public function getSenderMailbox()
    {
        return $this->resolve(self::KEY_SENDER_MAILBOX, 'senderMailbox');
    }

    /**
     * @return string
     */
    public function getGraphBaseUrl()
    {
        return $this->resolve(self::KEY_GRAPH_BASE_URL, '', 'https://graph.microsoft.com');
    }

    /**
     * @return string
     */
    public function getLoginBaseUrl()
    {
        return $this->resolve(self::KEY_LOGIN_BASE_URL, '', 'https://login.microsoftonline.com');
    }

    /**
     * Everything needed to obtain a token, assembled with the same precedence rules.
     *
     * @return Credentials
     */
    public function getCredentials()
    {
        $method = $this->resolve(self::KEY_AUTH_METHOD, 'authMethod', Credentials::METHOD_CERTIFICATE);

        $credentials = new Credentials(
            $this->resolve(self::KEY_TENANT_ID, 'tenantId'),
            $this->resolve(self::KEY_CLIENT_ID, 'clientId'),
            $method
        );

        if ($method === Credentials::METHOD_CERTIFICATE) {
            return $credentials->withCertificate(
                $this->resolve(self::KEY_CERTIFICATE_PATH, 'certificatePath'),
                $this->resolve(self::KEY_CERTIFICATE_PASSPHRASE, 'certificatePassphrase'),
                $this->getCertificateAlgorithm()
            );
        }

        return $credentials->withClientSecret(
            $this->resolve(self::KEY_CLIENT_SECRET, 'clientSecret')
        );
    }

    /**
     * PS256 is what Microsoft's current certificate-credentials reference specifies. RS256 exists
     * only as a config-file escape hatch for a tenant that rejects the PSS assertion; there is
     * deliberately no UI for it.
     *
     * @return string
     */
    public function getCertificateAlgorithm()
    {
        $algorithm = strtoupper((string) self::readOverride(self::KEY_CERTIFICATE_ALGORITHM));

        return $algorithm === ClientAssertion::ALG_RS256
            ? ClientAssertion::ALG_RS256
            : ClientAssertion::ALG_PS256;
    }

    /**
     * Whether the plugin has enough to attempt a send at all.
     *
     * @return bool
     */
    public function isConfigured()
    {
        if (!$this->isEnabled() || $this->getSenderMailbox() === '') {
            return false;
        }

        try {
            $this->getCredentials()->validate();
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * A human-readable reason the plugin cannot send, for the settings page and the logs.
     *
     * @return string Empty when everything is in place.
     */
    public function getConfigurationProblem()
    {
        if (!$this->isEnabled()) {
            return 'Missivus is switched off in the settings';
        }

        if ($this->getSenderMailbox() === '') {
            return 'Missivus is not configured: missing sender mailbox';
        }

        try {
            $this->getCredentials()->validate();
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        return '';
    }
}
