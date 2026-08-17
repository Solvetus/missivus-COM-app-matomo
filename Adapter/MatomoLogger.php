<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Missivus\Adapter;

use Piwik\Log\LoggerInterface as MatomoLoggerInterface;
use Solvetus\Missivus\Contract\LoggerInterface;

/**
 * Bridges the portable logger contract onto Matomo's PSR-3 logger.
 *
 * Messages arrive already redacted, and are passed as a single pre-interpolated string so no
 * caller-supplied brace in a Graph error body can be mistaken for a PSR-3 placeholder.
 */
class MatomoLogger implements LoggerInterface
{
    /** @var MatomoLoggerInterface */
    private $logger;

    /**
     * @param MatomoLoggerInterface $logger
     */
    public function __construct(MatomoLoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function error($message)
    {
        $this->logger->error('{message}', array('message' => $message));
    }

    /**
     * {@inheritdoc}
     */
    public function warning($message)
    {
        $this->logger->warning('{message}', array('message' => $message));
    }

    /**
     * {@inheritdoc}
     */
    public function info($message)
    {
        $this->logger->info('{message}', array('message' => $message));
    }
}
