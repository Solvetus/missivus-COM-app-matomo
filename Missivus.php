<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-COM-app-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Missivus;

// Also required from config/config.php — whichever is reached first wins, and require_once makes
// the other call free.
require_once __DIR__ . '/libs/autoload.php';

/**
 * Missivus replaces Matomo's PHPMailer transport with one that posts to the Microsoft Graph API
 * using OAuth2 client credentials and the Mail.Send application permission.
 *
 * The transport swap itself happens in config/config.php; this class only registers the plugin's
 * front-end assets and translation keys.
 */
class Missivus extends \Piwik\Plugin
{
    /**
     * @return array
     */
    public function registerEvents()
    {
        return array(
            'AssetManager.getStylesheetFiles' => 'getStylesheetFiles',
            'Translate.getClientSideTranslationKeys' => 'getClientSideTranslationKeys',
        );
    }

    /**
     * @param array $stylesheets
     * @return void
     */
    public function getStylesheetFiles(&$stylesheets)
    {
        $stylesheets[] = 'plugins/Missivus/stylesheets/missivus.less';
    }

    /**
     * Keys the Vue component looks up client-side. Matomo only ships translations to the browser
     * when a plugin declares which ones it needs.
     *
     * @return string[]
     */
    public function getClientSideTranslationKeys(&$translationKeys)
    {
        $translationKeys[] = 'Missivus_SendTestEmail';
        $translationKeys[] = 'Missivus_SendingTestEmail';
        $translationKeys[] = 'Missivus_TestEmailSent';
        $translationKeys[] = 'Missivus_TestEmailFailed';
        $translationKeys[] = 'Missivus_TestEmailRecipientLabel';
        $translationKeys[] = 'Missivus_TestEmailNotReady';
    }
}
