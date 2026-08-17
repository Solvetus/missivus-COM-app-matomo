<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Solvetus\Missivus\Exception;

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

        if ($this->responseBody !== '') {
            $message .= ' — response: ' . $this->responseBody;
        }

        parent::__construct($message, $this->httpStatus, $previous);
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
