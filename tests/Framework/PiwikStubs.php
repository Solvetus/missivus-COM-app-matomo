<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-COM-app-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

/**
 * Just enough of Piwik\Mail and Piwik\Mail\Transport for the transport's own behaviour — forced
 * From, the fallback switch, attachment mapping — to be tested without a Matomo checkout.
 *
 * Defined only when the real classes are absent, so under `./console tests:run Missivus` the tests
 * run against Matomo's actual implementations. The getters mirror core/Mail.php on 5.x-dev; if
 * Matomo changes them, PLAN.md §10 item 3 is the thing to check.
 */

namespace Piwik {
    if (!class_exists('Piwik\\Mail', false)) {
        class Mail
        {
            protected $fromEmail = '';
            protected $fromName = '';
            protected $bodyHTML = '';
            protected $bodyText = '';
            protected $subject = '';
            protected $recipients = array();
            protected $replyTos = array();
            protected $bccs = array();
            protected $attachments = array();

            public function setFrom($email, $name = null)
            {
                $this->fromEmail = $email;
                $this->fromName = $name;
            }

            public function getFrom()
            {
                return $this->fromEmail;
            }

            public function getFromName()
            {
                return $this->fromName;
            }

            public function setBodyHtml($html)
            {
                $this->bodyHTML = $html;
            }

            public function getBodyHtml()
            {
                return $this->bodyHTML;
            }

            public function setBodyText($txt)
            {
                $this->bodyText = $txt;
            }

            public function getBodyText()
            {
                return $this->bodyText;
            }

            public function setSubject($subject)
            {
                $this->subject = $subject;
            }

            public function getSubject()
            {
                return $this->subject;
            }

            public function addTo($address, $name = '')
            {
                $this->recipients[$address] = $name;
            }

            public function getRecipients()
            {
                return $this->recipients;
            }

            public function addBcc($email, $name = '')
            {
                $this->bccs[$email] = $name;
            }

            public function getBccs()
            {
                return $this->bccs;
            }

            public function addReplyTo($email, $name = '')
            {
                $this->replyTos[$email] = $name;
            }

            public function getReplyTos()
            {
                return $this->replyTos;
            }

            public function addAttachment($body, $mimeType = '', $filename = null, $cid = null)
            {
                $this->attachments[] = array(
                    'content' => $body,
                    'filename' => $filename,
                    'mimetype' => $mimeType,
                    'cid' => $cid,
                );
            }

            public function getAttachments()
            {
                return $this->attachments;
            }
        }
    }
}

namespace Piwik\Mail {
    if (!class_exists('Piwik\\Mail\\Transport', false)) {
        class Transport
        {
            public function send(\Piwik\Mail $mail)
            {
                throw new \RuntimeException(
                    'The stock PHPMailer transport is not available in the standalone suite.'
                    . ' A test reaching this line meant to override sendWithDefaultTransport().'
                );
            }
        }
    }
}
