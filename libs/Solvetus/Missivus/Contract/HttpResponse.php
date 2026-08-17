<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Solvetus\Missivus\Contract;

/**
 * What an HttpClientInterface hands back. Deliberately dumb: status, body, headers.
 */
class HttpResponse
{
    /** @var int */
    private $status;

    /** @var string */
    private $body;

    /** @var array Header name (lowercased) => value. */
    private $headers;

    /**
     * @param int    $status
     * @param string $body
     * @param array  $headers
     */
    public function __construct($status, $body, array $headers = array())
    {
        $this->status = (int) $status;
        $this->body = (string) $body;

        $this->headers = array();
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    /**
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * @param string $name
     * @return string|null
     */
    public function getHeader($name)
    {
        $name = strtolower($name);

        return isset($this->headers[$name]) ? $this->headers[$name] : null;
    }

    /**
     * The response body decoded as JSON.
     *
     * @return array Empty when the body is absent or not a JSON object.
     */
    public function getJson()
    {
        if ($this->body === '') {
            return array();
        }

        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @return bool
     */
    public function isSuccess()
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
