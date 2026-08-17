<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Solvetus\Missivus\Contract;

/**
 * Somewhere to keep an access token between requests. Matomo backs this with Piwik\Cache;
 * WordPress will back it with a transient.
 *
 * Whatever backs it holds a bearer token, so it must not be world-readable and must not be
 * serialised into logs or debug output.
 */
interface TokenCacheInterface
{
    /**
     * @param string $key
     * @return string|null The cached value, or null when absent or expired.
     */
    public function get($key);

    /**
     * @param string $key
     * @param string $value
     * @param int    $ttlSeconds Seconds until the entry must be treated as absent.
     * @return void
     */
    public function set($key, $value, $ttlSeconds);

    /**
     * @param string $key
     * @return void
     */
    public function delete($key);
}
