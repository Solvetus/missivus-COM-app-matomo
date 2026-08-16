<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-COM-app-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

/**
 * Matomo autoloads `Piwik\Plugins\<Name>\*` out of the plugin directory and has no support for a
 * per-plugin vendor/autoload.php or Bootstrap.php. The portable half of this plugin deliberately
 * lives under a Matomo-free namespace so the WordPress sibling can vendor it unchanged, so it needs
 * its own twelve lines of autoloader.
 *
 * Required from both config/config.php (evaluated when the DI container is built) and Missivus.php
 * (loaded by the plugin manager) — either can be reached first, and require_once makes the second
 * call free.
 */
spl_autoload_register(function ($class) {
    $prefix = 'Solvetus\\Missivus\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/Solvetus/Missivus/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
