<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-COM-app-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

/**
 * The DI wiring, and the whole reason this plugin needs no core patch.
 *
 * Piwik\Mail::send() ends with StaticContainer::get('Piwik\Mail\Transport')->send($mail) — the
 * string container key introduced by matomo-org/matomo#14041 ("Add possibility to change mail
 * transport through DI", milestone 3.9.0). Matomo's ContainerFactory loads this file for every
 * activated plugin, so rebinding that key here swaps the transport for the whole installation.
 *
 * Deactivating the plugin stops this file being loaded, which restores the stock transport with
 * no cleanup at all.
 */

// The portable half lives under a Matomo-free namespace and needs its own autoloader. This file is
// evaluated when the container is built, which can happen before the plugin class loads.
require_once __DIR__ . '/../libs/autoload.php';

return array(
    // The seam itself.
    'Piwik\Mail\Transport' => Piwik\DI::autowire(
        Piwik\Plugins\Missivus\Mail\GraphTransport::class
    ),

    // The transport reads configuration through an interface so its own behaviour can be tested
    // without a Matomo installation; Configuration\Settings is the implementation that reads the
    // environment, config.ini.php and the settings table.
    Piwik\Plugins\Missivus\Configuration\ConfigurationInterface::class => Piwik\DI::autowire(
        Piwik\Plugins\Missivus\Configuration\Settings::class
    ),

    // The portable contracts, satisfied by the thin Matomo adapters.
    Solvetus\Missivus\Contract\HttpClientInterface::class => Piwik\DI::autowire(
        Piwik\Plugins\Missivus\Adapter\MatomoHttpClient::class
    ),
    Solvetus\Missivus\Contract\TokenCacheInterface::class => Piwik\DI::autowire(
        Piwik\Plugins\Missivus\Adapter\MatomoTokenCache::class
    ),
    Solvetus\Missivus\Contract\LoggerInterface::class => Piwik\DI::autowire(
        Piwik\Plugins\Missivus\Adapter\MatomoLogger::class
    ),
);
