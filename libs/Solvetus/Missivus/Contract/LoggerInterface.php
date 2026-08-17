<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Solvetus\Missivus\Contract;

/**
 * A PSR-3-shaped subset, declared locally so the portable half needs no Composer package.
 * Implementations receive messages that have already been passed through Redactor.
 */
interface LoggerInterface
{
    /**
     * @param string $message
     * @return void
     */
    public function error($message);

    /**
     * @param string $message
     * @return void
     */
    public function warning($message);

    /**
     * @param string $message
     * @return void
     */
    public function info($message);
}
