<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Missivus\Adapter;

use Piwik\Http;
use Solvetus\Missivus\Contract\HttpClientInterface;
use Solvetus\Missivus\Contract\HttpResponse;

/**
 * HttpClientInterface over Piwik\Http, so Missivus inherits Matomo's proxy settings, CA bundle and
 * timeouts rather than opening its own curl handles.
 *
 * Piwik\Http::sendHttpRequestBy() is positional with nineteen parameters and no options array, so
 * this class is where an upstream signature change would bite. It is item 4 in PLAN.md §10.
 */
class MatomoHttpClient implements HttpClientInterface
{
    const USER_AGENT = 'Matomo/Missivus';

    /**
     * {@inheritdoc}
     */
    public function post($url, $body, array $headers = array(), $timeout = 30)
    {
        return $this->request('POST', $url, $body, $headers, $timeout);
    }

    /**
     * {@inheritdoc}
     */
    public function put($url, $body, array $headers = array(), $timeout = 60)
    {
        return $this->request('PUT', $url, $body, $headers, $timeout);
    }

    /**
     * @param string $method
     * @param string $url
     * @param string $body
     * @param array  $headers
     * @param int    $timeout
     * @return HttpResponse
     * @throws \RuntimeException
     */
    private function request($method, $url, $body, array $headers, $timeout)
    {
        try {
            $result = Http::sendHttpRequestBy(
                Http::getTransportMethod(),
                $url,
                $timeout,
                self::USER_AGENT,
                null,               // destinationPath — we want the body back, not a file
                null,               // file
                0,                  // followDepth
                false,              // acceptLanguage
                false,              // acceptInvalidSslCertificate
                false,              // byteRange
                true,               // getExtendedInfo — gives us status + headers + data
                $method,            // httpMethod
                null,               // httpUsername
                null,               // httpPassword
                (string) $body,     // a string body is passed through verbatim; only arrays get query-encoded
                $this->formatHeaders($headers)
            );
        } catch (\Exception $e) {
            // The contract says transport failures throw and HTTP error statuses do not.
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        if (!is_array($result)) {
            throw new \RuntimeException('Unexpected response from Piwik\Http for ' . $method . ' request');
        }

        return new HttpResponse(
            isset($result['status']) ? (int) $result['status'] : 0,
            isset($result['data']) ? (string) $result['data'] : '',
            isset($result['headers']) && is_array($result['headers']) ? $result['headers'] : array()
        );
    }

    /**
     * Piwik\Http wants raw header lines, not a map.
     *
     * @param array $headers
     * @return string[]
     */
    private function formatHeaders(array $headers)
    {
        $lines = array();

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }
}
