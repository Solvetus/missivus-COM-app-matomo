<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Solvetus\Missivus\Contract;

/**
 * The whole HTTP surface Missivus needs — two methods, so a host platform can satisfy it with
 * whatever it already ships. Matomo wraps Piwik\Http; WordPress will wrap wp_remote_post.
 *
 * Implementations MUST NOT throw on an HTTP error status: a 4xx or 5xx is a normal return with that
 * status set. Throw only when the request could not be made at all (DNS, TLS, timeout), and use
 * \RuntimeException for it.
 */
interface HttpClientInterface
{
    /**
     * POST a body and return the response.
     *
     * @param string $url
     * @param string $body        Already serialised — JSON or form-encoded. Sent verbatim.
     * @param array  $headers     Header name => value.
     * @param int    $timeout     Seconds.
     * @return HttpResponse
     * @throws \RuntimeException When the request could not be completed at all.
     */
    public function post($url, $body, array $headers = array(), $timeout = 30);

    /**
     * PUT raw bytes and return the response. Used for attachment upload-session chunks.
     *
     * @param string $url
     * @param string $body        Raw bytes.
     * @param array  $headers     Header name => value.
     * @param int    $timeout     Seconds.
     * @return HttpResponse
     * @throws \RuntimeException When the request could not be completed at all.
     */
    public function put($url, $body, array $headers = array(), $timeout = 60);
}
