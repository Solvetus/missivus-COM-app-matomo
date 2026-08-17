<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Solvetus\Missivus\Contract;

/**
 * Default when a host passes no logger. Never used by the Matomo plugin, which always wires
 * Matomo's own logger.
 */
class NullLogger implements LoggerInterface
{
    public function error($message)
    {
    }

    public function warning($message)
    {
    }

    public function info($message)
    {
    }
}
