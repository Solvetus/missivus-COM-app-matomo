<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-COM-app-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Missivus\Adapter;

use Piwik\Cache;
use Solvetus\Missivus\Contract\TokenCacheInterface;

/**
 * TokenCacheInterface over Matomo's lazy cache, which persists across requests — a token cached
 * only for the lifetime of one PHP request would mean a token round-trip on every single email.
 */
class MatomoTokenCache implements TokenCacheInterface
{
    /**
     * {@inheritdoc}
     */
    public function get($key)
    {
        $cache = Cache::getLazyCache();

        if (!$cache->contains($key)) {
            return null;
        }

        $value = $cache->fetch($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $ttlSeconds)
    {
        $ttlSeconds = (int) $ttlSeconds;

        if ($ttlSeconds <= 0) {
            return;
        }

        Cache::getLazyCache()->save($key, $value, $ttlSeconds);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        Cache::getLazyCache()->delete($key);
    }
}
