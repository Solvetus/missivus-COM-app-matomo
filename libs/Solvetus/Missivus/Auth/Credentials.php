<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Solvetus\Missivus\Auth;

use Solvetus\Missivus\Exception\GraphException;

/**
 * Immutable holder for the app registration's identity and its one credential.
 *
 * __toString() and __debugInfo() are overridden so that a var_dump, a print_r, or an uncaught
 * exception's stack trace cannot spill the secret. That is not paranoia: PHP puts constructor
 * arguments into stack traces by default, which is exactly how credentials end up in error logs.
 */
class Credentials
{
    const METHOD_SECRET = 'secret';
    const METHOD_CERTIFICATE = 'certificate';

    /** @var string */
    private $tenantId;

    /** @var string */
    private $clientId;

    /** @var string */
    private $method;

    /** @var string */
    private $clientSecret = '';

    /** @var string */
    private $certificatePath = '';

    /** @var string */
    private $certificatePassphrase = '';

    /** @var string 'PS256' (what Entra's current reference specifies) or 'RS256'. */
    private $certificateAlgorithm = ClientAssertion::ALG_PS256;

    /**
     * @param string $tenantId
     * @param string $clientId
     * @param string $method One of the METHOD_* constants.
     */
    public function __construct($tenantId, $clientId, $method)
    {
        $this->tenantId = trim((string) $tenantId);
        $this->clientId = trim((string) $clientId);
        $this->method = $method === self::METHOD_CERTIFICATE ? self::METHOD_CERTIFICATE : self::METHOD_SECRET;
    }

    /**
     * @param string $secret
     * @return self
     */
    public function withClientSecret($secret)
    {
        $clone = clone $this;
        $clone->method = self::METHOD_SECRET;
        $clone->clientSecret = (string) $secret;

        return $clone;
    }

    /**
     * @param string $path
     * @param string $passphrase
     * @param string $algorithm
     * @return self
     */
    public function withCertificate($path, $passphrase = '', $algorithm = ClientAssertion::ALG_PS256)
    {
        $clone = clone $this;
        $clone->method = self::METHOD_CERTIFICATE;
        $clone->certificatePath = (string) $path;
        $clone->certificatePassphrase = (string) $passphrase;
        $clone->certificateAlgorithm = $algorithm === ClientAssertion::ALG_RS256
            ? ClientAssertion::ALG_RS256
            : ClientAssertion::ALG_PS256;

        return $clone;
    }

    /**
     * Fail before any network call if something obvious is missing, so the operator gets
     * "sender mailbox is not set" rather than an opaque Entra rejection.
     *
     * @return void
     * @throws GraphException
     */
    public function validate()
    {
        $missing = array();

        if ($this->tenantId === '') {
            $missing[] = 'tenant ID';
        }
        if ($this->clientId === '') {
            $missing[] = 'client ID';
        }

        if ($this->method === self::METHOD_CERTIFICATE) {
            if ($this->certificatePath === '') {
                $missing[] = 'certificate path';
            } elseif (!is_readable($this->certificatePath)) {
                // The path, never the contents.
                throw new GraphException(
                    'Missivus: certificate file is not readable at ' . $this->certificatePath
                );
            }
        } elseif ($this->clientSecret === '') {
            $missing[] = 'client secret';
        }

        if (!empty($missing)) {
            throw new GraphException('Missivus is not configured: missing ' . implode(', ', $missing));
        }
    }

    /**
     * @return string
     */
    public function getTenantId()
    {
        return $this->tenantId;
    }

    /**
     * @return string
     */
    public function getClientId()
    {
        return $this->clientId;
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * @return bool
     */
    public function usesCertificate()
    {
        return $this->method === self::METHOD_CERTIFICATE;
    }

    /**
     * @return string
     */
    public function getClientSecret()
    {
        return $this->clientSecret;
    }

    /**
     * @return string
     */
    public function getCertificatePath()
    {
        return $this->certificatePath;
    }

    /**
     * @return string
     */
    public function getCertificatePassphrase()
    {
        return $this->certificatePassphrase;
    }

    /**
     * @return string
     */
    public function getCertificateAlgorithm()
    {
        return $this->certificateAlgorithm;
    }

    /**
     * Every secret value we hold, for Redactor to blank out of logs.
     *
     * @return string[]
     */
    public function getSecretLiterals()
    {
        return array_values(array_filter(array($this->clientSecret, $this->certificatePassphrase)));
    }

    /**
     * Stable, non-secret identity for the token cache key.
     *
     * @return string
     */
    public function getCacheDiscriminator()
    {
        return sha1($this->tenantId . '|' . $this->clientId . '|' . $this->method);
    }

    /**
     * @return array
     */
    public function __debugInfo()
    {
        return array(
            'tenantId' => $this->tenantId,
            'clientId' => $this->clientId,
            'method' => $this->method,
            'clientSecret' => $this->clientSecret === '' ? '(unset)' : '(set, redacted)',
            'certificatePath' => $this->certificatePath,
            'certificatePassphrase' => $this->certificatePassphrase === '' ? '(unset)' : '(set, redacted)',
        );
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return 'Missivus\Credentials(tenant=' . $this->tenantId . ', client=' . $this->clientId
            . ', method=' . $this->method . ', credential=redacted)';
    }
}
