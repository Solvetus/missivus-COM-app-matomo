<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Missivus\Configuration;

use Solvetus\Missivus\Auth\Credentials;

/**
 * What the transport needs to know, expressed without reference to where it came from.
 *
 * Config\Settings is the real implementation, reading environment, config.ini.php and the
 * SystemSettings table in that order. Having the seam means the transport's own behaviour —
 * forced From, the fallback switch, the not-configured path — can be tested without standing up a
 * Matomo installation, which is the difference between tests that get run and tests that do not.
 */
interface ConfigurationInterface
{
    /**
     * @return bool
     */
    public function isEnabled();

    /**
     * @return bool
     */
    public function shouldFallBackToDefaultTransport();

    /**
     * @return bool
     */
    public function shouldSaveToSentItems();

    /**
     * @return string
     */
    public function getSenderMailbox();

    /**
     * @return string
     */
    public function getGraphBaseUrl();

    /**
     * @return string
     */
    public function getLoginBaseUrl();

    /**
     * @return Credentials
     */
    public function getCredentials();

    /**
     * @return string Empty when the plugin has everything it needs to send.
     */
    public function getConfigurationProblem();
}
