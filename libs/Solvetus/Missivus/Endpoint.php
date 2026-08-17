<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Solvetus\Missivus;

use Solvetus\Missivus\Exception\GraphException;

/**
 * Where a request is allowed to go.
 *
 * Both base URLs are overridable, because sovereign clouds and the test suite need them to be. That
 * override is also the one knob that can point a client secret or a bearer token at a host we never
 * meant to talk to, so every base URL is normalised here, once, and anything that is not a bare
 * https origin is refused loudly instead of quietly used.
 */
class Endpoint
{
    /**
     * @param string $url     The configured base URL.
     * @param string $setting The setting name, for the error message.
     * @return string The normalised origin, without a trailing slash.
     * @throws GraphException When the URL is anything other than a bare https origin.
     */
    public static function normalise($url, $setting)
    {
        $url = rtrim(trim((string) $url), '/');

        if ($url === '') {
            throw new GraphException('Missivus: ' . $setting . ' must not be empty');
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme']) || !isset($parts['host'])) {
            throw new GraphException('Missivus: ' . $setting . ' is not a valid URL: ' . $url);
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new GraphException(
                'Missivus: ' . $setting . ' must be an https:// URL. Refusing to send credentials to ' . $url
            );
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new GraphException(
                'Missivus: ' . $setting . ' must be a bare https origin, with no credentials or query string: ' . $url
            );
        }

        if (!preg_match('/^[A-Za-z0-9.-]+$/', $parts['host'])) {
            throw new GraphException('Missivus: ' . $setting . ' has an invalid host: ' . $url);
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';

        return 'https://' . $parts['host'] . $port . $path;
    }
}
