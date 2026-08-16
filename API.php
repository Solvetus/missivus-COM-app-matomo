<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-COM-app-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Missivus;

use Piwik\Container\StaticContainer;
use Piwik\Mail;
use Piwik\Piwik;
use Piwik\Plugins\Missivus\Config\ConfigurationInterface;
use Piwik\Plugins\Missivus\Mail\GraphTransport;

/**
 * The Missivus API.
 *
 * SystemSettings has no button primitive, so the "send test email" button in the settings page is
 * a small Vue component calling this method. Reachable over token_auth like any Matomo API method,
 * and gated on superuser access because it exposes tenant-level error detail.
 *
 * @method static \Piwik\Plugins\Missivus\API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    /**
     * Send a test email over Microsoft Graph and report exactly what happened.
     *
     * The fallback setting is deliberately ignored: a test that quietly succeeded over SMTP would
     * tell the operator nothing about whether their Entra app registration works.
     *
     * @param string|false $to Recipient. Defaults to the current user's own email address.
     * @return array ['success' => bool, 'message' => string]
     */
    public function sendTestEmail($to = false)
    {
        Piwik::checkUserHasSuperUserAccess();

        $recipient = $this->resolveRecipient($to);

        if ($recipient === '') {
            return array(
                'success' => false,
                'message' => Piwik::translate('Missivus_TestEmailNoRecipient'),
            );
        }

        /** @var ConfigurationInterface $config */
        $config = StaticContainer::get(ConfigurationInterface::class);

        $problem = $config->getConfigurationProblem();
        if ($problem !== '') {
            return array('success' => false, 'message' => $problem);
        }

        $mail = new Mail();
        $mail->setFrom($config->getSenderMailbox());
        $mail->addTo($recipient);
        $mail->setSubject(Piwik::translate('Missivus_TestEmailSubject'));
        $mail->setBodyText(Piwik::translate('Missivus_TestEmailBody', array($config->getSenderMailbox())));

        $transport = new GraphTransport(
            $config,
            StaticContainer::get(Adapter\MatomoHttpClient::class),
            StaticContainer::get(Adapter\MatomoTokenCache::class),
            StaticContainer::get(Adapter\MatomoLogger::class)
        );

        try {
            $transport->sendWithoutFallback($mail);
        } catch (\Exception $e) {
            // The Graph error body is already redacted and is the single most useful thing for
            // diagnosing a misconfigured tenant, so it is surfaced rather than generalised away.
            return array('success' => false, 'message' => $e->getMessage());
        }

        return array(
            'success' => true,
            'message' => Piwik::translate('Missivus_TestEmailSentTo', array($recipient)),
        );
    }

    /**
     * @param string|false $to
     * @return string
     */
    private function resolveRecipient($to)
    {
        $to = trim((string) $to);

        if ($to !== '') {
            return $to;
        }

        return trim((string) Piwik::getCurrentUserEmail());
    }
}
