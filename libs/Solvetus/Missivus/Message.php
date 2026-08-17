<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Solvetus\Missivus;

/**
 * A transport-neutral email. Hosts translate their own mail object into this; GraphMailer turns it
 * into Graph JSON.
 *
 * CC is modelled even though Piwik\Mail has no CC API, because the WordPress sibling vendors this
 * class unchanged and wp_mail does support it.
 */
class Message
{
    /** @var string */
    private $fromAddress = '';

    /** @var string */
    private $fromName = '';

    /** @var array Each entry: ['address' => string, 'name' => string] */
    private $to = array();

    /** @var array */
    private $cc = array();

    /** @var array */
    private $bcc = array();

    /** @var array */
    private $replyTo = array();

    /** @var string */
    private $subject = '';

    /** @var string */
    private $html = '';

    /** @var string */
    private $text = '';

    /** @var Attachment[] */
    private $attachments = array();

    /**
     * @param string $address
     * @param string $name
     * @return $this
     */
    public function setFrom($address, $name = '')
    {
        $this->fromAddress = (string) $address;
        $this->fromName = (string) $name;

        return $this;
    }

    /**
     * @param string $address
     * @param string $name
     * @return $this
     */
    public function addTo($address, $name = '')
    {
        return $this->addRecipient($this->to, $address, $name);
    }

    /**
     * @param string $address
     * @param string $name
     * @return $this
     */
    public function addCc($address, $name = '')
    {
        return $this->addRecipient($this->cc, $address, $name);
    }

    /**
     * @param string $address
     * @param string $name
     * @return $this
     */
    public function addBcc($address, $name = '')
    {
        return $this->addRecipient($this->bcc, $address, $name);
    }

    /**
     * @param string $address
     * @param string $name
     * @return $this
     */
    public function addReplyTo($address, $name = '')
    {
        return $this->addRecipient($this->replyTo, $address, $name);
    }

    /**
     * @param array  $bucket
     * @param string $address
     * @param string $name
     * @return $this
     */
    private function addRecipient(array &$bucket, $address, $name)
    {
        $address = trim((string) $address);

        if ($address !== '') {
            $bucket[] = array('address' => $address, 'name' => (string) $name);
        }

        return $this;
    }

    /**
     * @param string $subject
     * @return $this
     */
    public function setSubject($subject)
    {
        $this->subject = (string) $subject;

        return $this;
    }

    /**
     * @param string $html
     * @return $this
     */
    public function setHtmlBody($html)
    {
        $this->html = (string) $html;

        return $this;
    }

    /**
     * @param string $text
     * @return $this
     */
    public function setTextBody($text)
    {
        $this->text = (string) $text;

        return $this;
    }

    /**
     * @param Attachment $attachment
     * @return $this
     */
    public function addAttachment(Attachment $attachment)
    {
        $this->attachments[] = $attachment;

        return $this;
    }

    /**
     * @return string
     */
    public function getFromAddress()
    {
        return $this->fromAddress;
    }

    /**
     * @return string
     */
    public function getFromName()
    {
        return $this->fromName;
    }

    /**
     * @return array
     */
    public function getTo()
    {
        return $this->to;
    }

    /**
     * @return array
     */
    public function getCc()
    {
        return $this->cc;
    }

    /**
     * @return array
     */
    public function getBcc()
    {
        return $this->bcc;
    }

    /**
     * @return array
     */
    public function getReplyTo()
    {
        return $this->replyTo;
    }

    /**
     * @return bool
     */
    public function hasReplyTo()
    {
        return !empty($this->replyTo);
    }

    /**
     * @return string
     */
    public function getSubject()
    {
        return $this->subject;
    }

    /**
     * @return string
     */
    public function getHtmlBody()
    {
        return $this->html;
    }

    /**
     * @return string
     */
    public function getTextBody()
    {
        return $this->text;
    }

    /**
     * @return Attachment[]
     */
    public function getAttachments()
    {
        return $this->attachments;
    }

    /**
     * @return bool
     */
    public function hasRecipients()
    {
        return !empty($this->to) || !empty($this->cc) || !empty($this->bcc);
    }

    /**
     * The Graph `message` resource, minus attachments — those differ between the two send paths.
     *
     * @return array
     */
    public function toGraphMessage()
    {
        $message = array(
            'subject' => $this->subject,
            'body' => $this->html !== ''
                ? array('contentType' => 'HTML', 'content' => $this->html)
                : array('contentType' => 'Text', 'content' => $this->text),
            'toRecipients' => self::toGraphRecipients($this->to),
        );

        if (!empty($this->cc)) {
            $message['ccRecipients'] = self::toGraphRecipients($this->cc);
        }

        if (!empty($this->bcc)) {
            $message['bccRecipients'] = self::toGraphRecipients($this->bcc);
        }

        if (!empty($this->replyTo)) {
            $message['replyTo'] = self::toGraphRecipients($this->replyTo);
        }

        if ($this->fromAddress !== '') {
            $message['from'] = self::toGraphRecipient($this->fromAddress, $this->fromName);
        }

        return $message;
    }

    /**
     * @param array $recipients
     * @return array
     */
    private static function toGraphRecipients(array $recipients)
    {
        $out = array();

        foreach ($recipients as $recipient) {
            $out[] = self::toGraphRecipient($recipient['address'], $recipient['name']);
        }

        return $out;
    }

    /**
     * @param string $address
     * @param string $name
     * @return array
     */
    private static function toGraphRecipient($address, $name)
    {
        $emailAddress = array('address' => $address);

        if ((string) $name !== '') {
            $emailAddress['name'] = $name;
        }

        return array('emailAddress' => $emailAddress);
    }
}
