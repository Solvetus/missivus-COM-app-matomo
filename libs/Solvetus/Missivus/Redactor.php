<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Solvetus\Missivus;

/**
 * The last thing between a credential and a log file.
 *
 * Everything Missivus logs or puts in an exception message goes through here first. Two layers,
 * because either alone would miss something:
 *
 *  1. Known literals — the actual secret and passphrase we hold, replaced wherever they appear.
 *     Catches the case where a server echoes a submitted value straight back.
 *  2. Shape matching — bearer tokens, client_secret=/client_assertion= parameters, and JWTs.
 *     Catches values we were never given, such as an access token inside an Entra error payload.
 */
class Redactor
{
    const MASK = '***redacted***';

    /** @var string[] */
    private $literals = array();

    /**
     * @param string[] $literals Secret values to blank wherever they appear.
     */
    public function __construct(array $literals = array())
    {
        foreach ($literals as $literal) {
            // Very short strings would mangle unrelated text for no security gain.
            if (is_string($literal) && strlen($literal) >= 8) {
                $this->literals[] = $literal;
            }
        }
    }

    /**
     * @param string $text
     * @return string
     */
    public function redact($text)
    {
        $text = (string) $text;

        if ($text === '') {
            return $text;
        }

        foreach ($this->literals as $literal) {
            $text = str_replace($literal, self::MASK, $text);
        }

        $patterns = array(
            // "access_token":"eyJ0..." and friends, in JSON. uploadUrl belongs in this list: it is
            // pre-authenticated, so anyone holding it can write to the draft it was issued for.
            '/("(?:access_token|refresh_token|id_token|client_secret|client_assertion|uploadUrl)"\s*:\s*")[^"]*(")/i',
            // client_secret=... in a form-encoded body.
            '/((?:client_secret|client_assertion|access_token)=)[^&\s]+/i',
            // Authorization: Bearer <token>
            '/(Bearer\s+)[A-Za-z0-9\-._~+\/]+=*/i',
            // A bare JWT anywhere at all.
            '/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]*/',
        );

        $replacements = array(
            '$1' . self::MASK . '$2',
            '$1' . self::MASK,
            '$1' . self::MASK,
            self::MASK,
        );

        $redacted = preg_replace($patterns, $replacements, $text);

        // preg_replace returns null on failure (e.g. backtrack limit on a huge body). Failing
        // closed matters more than a readable log line.
        return $redacted === null ? self::MASK : $redacted;
    }

    /**
     * Trim a Graph error body to something loggable, then redact it.
     *
     * @param string $body
     * @param int    $maxLength
     * @return string
     */
    public function redactBody($body, $maxLength = 2000)
    {
        $body = (string) $body;

        if (strlen($body) > $maxLength) {
            $body = substr($body, 0, $maxLength) . '… (truncated)';
        }

        return $this->redact($body);
    }
}
