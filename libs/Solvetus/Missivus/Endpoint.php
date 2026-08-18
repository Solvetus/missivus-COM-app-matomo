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
 *
 * Refused loudly, but never verbatim. The rejected value is the operator's own configuration, and a
 * misconfiguration is exactly the case where it might hold a credential — `https://user:pass@host`,
 * `https://host?access_token=…`. So no message built here ever contains the userinfo, the query
 * string or the fragment: an error names the scheme, host, port and path, and nothing else, or it
 * names only the reason it was refused.
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
            throw self::refuse($setting, 'must not be empty');
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme']) || !isset($parts['host'])) {
            // Nothing from a string this malformed is safe to echo back. It has no recognisable
            // structure, so there is no part of it we can say is not a password.
            throw self::refuse($setting, 'is not a valid endpoint URL: expected scheme://host');
        }

        if (!preg_match('/^[A-Za-z0-9.-]+$/', $parts['host'])) {
            // Checked before anything is echoed, not after, so that every message built below
            // carries a host we have already established is an ordinary host name.
            throw self::refuse($setting, 'has an invalid host name');
        }

        // Built once here and reused as the return value, so there is exactly one place that
        // decides which components of a URL are allowed to be seen.
        $safe = self::describe($parts);

        if (strtolower($parts['scheme']) !== 'https') {
            throw self::refuse($setting, 'must be an https:// URL. Refusing to send credentials to ' . $safe);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw self::refuse($setting, 'must not carry a username or password in the URL: ' . $safe);
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw self::refuse($setting, 'must be a bare https origin, with no query string or fragment: ' . $safe);
        }

        return $safe;
    }

    /**
     * The only components of a parsed URL that are ever allowed into a message: scheme, host, port
     * and path. Userinfo, query and fragment are dropped, not masked — they are never assembled.
     *
     * @param array $parts The output of parse_url(), with scheme and host known to be present.
     * @return string
     */
    private static function describe(array $parts)
    {
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';

        return strtolower($parts['scheme']) . '://' . $parts['host'] . $port . $path;
    }

    /**
     * @param string $setting
     * @param string $reason
     * @return GraphException
     */
    private static function refuse($setting, $reason)
    {
        return new GraphException('Missivus: ' . $setting . ' ' . $reason);
    }
}
