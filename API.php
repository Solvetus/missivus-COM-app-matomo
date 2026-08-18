<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Missivus;

use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\Mail;
use Piwik\Piwik;
use Piwik\Plugins\Missivus\Configuration\ConfigurationInterface;
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
        $this->checkRequestIsPost();

        $recipient = $this->resolveRecipient($to);

        if ($recipient === '') {
            return array(
                'success' => false,
                'message' => Piwik::translate('Missivus_TestEmailNoRecipient'),
            );
        }

        if (!Piwik::isValidEmailString($recipient)) {
            // Refused before a Mail object exists, so nothing malformed ever reaches Graph.
            return array(
                'success' => false,
                'message' => Piwik::translate('Missivus_TestEmailInvalidRecipient'),
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
            StaticContainer::get(\Solvetus\Missivus\Contract\HttpClientInterface::class),
            StaticContainer::get(\Solvetus\Missivus\Contract\TokenCacheInterface::class),
            StaticContainer::get(\Solvetus\Missivus\Contract\LoggerInterface::class)
        );

        try {
            $transport->sendWithoutFallback($mail);
        } catch (\Exception $e) {
            // The Graph error body is the single most useful thing for diagnosing a misconfigured
            // tenant, so it is surfaced rather than generalised away — but only after the same
            // redaction pass the transport applies to everything it logs. This response is rendered
            // in a browser, which is the one place a leaked endpoint credential would be read by a
            // human rather than merely written to a file.
            return array('success' => false, 'message' => $transport->redact($e->getMessage()));
        }

        return array(
            'success' => true,
            'message' => Piwik::translate('Missivus_TestEmailSentTo', array($recipient)),
        );
    }

    /**
     * Whether the settings that are *saved* are enough to send a test email.
     *
     * The button asks this on load and again after a save, because the test sends with the stored
     * configuration — not with whatever is currently typed into the form. Before this existed the
     * usual first experience of the plugin was filling the form in, clicking "Send test email", and
     * being told Missivus was switched off.
     *
     * @return array ['ready' => bool, 'reason' => string]
     */
    public function getTestEmailStatus()
    {
        Piwik::checkUserHasSuperUserAccess();

        /** @var ConfigurationInterface $config */
        $config = StaticContainer::get(ConfigurationInterface::class);

        $problem = $config->getConfigurationProblem();

        if ($problem === '') {
            return array('ready' => true, 'reason' => '');
        }

        return array(
            'ready' => false,
            'reason' => Piwik::translate('Missivus_TestEmailNotReady') . ' (' . $problem . ')',
        );
    }

    /**
     * Refuse a GET.
     *
     * Matomo already makes a cross-site call impossible: for `module=API` requests it does not
     * authenticate from the session cookie at all (FrontController::makeSessionAuthenticator), so a
     * caller must present the token_auth that only a page inside Matomo can read. This adds the
     * cheap second lock — sending mail is a state change, and a state change should not be reachable
     * by a URL that a browser can be talked into visiting.
     *
     * @return void
     * @throws \Exception
     */
    private function checkRequestIsPost()
    {
        if (Common::isPhpCliMode()) {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';

        if ($method !== 'POST') {
            throw new \Exception(Piwik::translate('Missivus_TestEmailRequiresPost'));
        }
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
