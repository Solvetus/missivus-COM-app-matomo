<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Solvetus\Missivus\Exception;

use Solvetus\Missivus\Redactor;

/**
 * Every failure Missivus raises. Carries the HTTP status and the response body, both already
 * redacted by whoever constructed it — a Graph or Entra error body is the single most useful thing
 * for diagnosing a misconfigured tenant, so it is deliberately preserved rather than swallowed.
 */
class GraphException extends \RuntimeException
{
    /** @var int 0 when the failure was not an HTTP response (bad config, unreadable key, …). */
    private $httpStatus;

    /** @var string */
    private $responseBody;

    /** @var string The message as given, before the response body was appended to it. */
    private $baseMessage;

    /**
     * @param string          $message
     * @param int             $httpStatus
     * @param string          $responseBody Must already be redacted.
     * @param \Exception|null $previous
     */
    public function __construct($message, $httpStatus = 0, $responseBody = '', $previous = null)
    {
        $this->httpStatus = (int) $httpStatus;
        $this->responseBody = (string) $responseBody;
        $this->baseMessage = (string) $message;

        if ($this->responseBody !== '') {
            $message .= ' — response: ' . $this->responseBody;
        }

        parent::__construct($message, $this->httpStatus, $previous);
    }

    /**
     * The same failure with every string it carries put through one final redaction pass.
     *
     * Belt and braces. Whoever constructed the exception was supposed to have redacted already, and
     * normally had; this is the last gate before a message reaches a log file or a superuser's
     * screen, and it costs one regex sweep. Returns $this untouched when there was nothing to hide,
     * so the ordinary path keeps its original stack trace.
     *
     * The replacement deliberately does not chain the original as `previous`: a handler that prints
     * the whole chain would otherwise print the very text this pass exists to remove.
     *
     * @param Redactor $redactor
     * @return GraphException
     */
    public function redactedWith(Redactor $redactor)
    {
        $message = $redactor->redact($this->baseMessage);
        $body = $redactor->redact($this->responseBody);

        if ($message === $this->baseMessage && $body === $this->responseBody) {
            return $this;
        }

        return new self($message, $this->httpStatus, $body);
    }

    /**
     * @return int
     */
    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    /**
     * @return string
     */
    public function getResponseBody()
    {
        return $this->responseBody;
    }
}
