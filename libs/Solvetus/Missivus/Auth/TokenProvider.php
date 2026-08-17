<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Solvetus\Missivus\Auth;

use Solvetus\Missivus\Contract\HttpClientInterface;
use Solvetus\Missivus\Contract\TokenCacheInterface;
use Solvetus\Missivus\Endpoint;
use Solvetus\Missivus\Exception\GraphException;
use Solvetus\Missivus\Redactor;

/**
 * OAuth2 client-credentials tokens, cached and refreshed.
 *
 * There is no refresh token in this grant — expiry simply means asking again. The cache TTL is set
 * five minutes short of the real expiry so a token is never presented to Graph in its last moments,
 * which is the same margin solvetus.com's contact-form worker uses.
 */
class TokenProvider
{
    const SCOPE = 'https://graph.microsoft.com/.default';

    /** Renew this many seconds before Microsoft's stated expiry. */
    const EXPIRY_MARGIN_SECONDS = 300;

    const CACHE_PREFIX = 'missivus.token.';

    /** @var Credentials */
    private $credentials;

    /** @var HttpClientInterface */
    private $http;

    /** @var TokenCacheInterface */
    private $cache;

    /** @var Redactor */
    private $redactor;

    /** @var string */
    private $loginBaseUrl;

    /**
     * @param Credentials         $credentials
     * @param HttpClientInterface $http
     * @param TokenCacheInterface $cache
     * @param Redactor            $redactor
     * @param string              $loginBaseUrl
     */
    public function __construct(
        Credentials $credentials,
        HttpClientInterface $http,
        TokenCacheInterface $cache,
        Redactor $redactor,
        $loginBaseUrl = 'https://login.microsoftonline.com'
    ) {
        $this->credentials = $credentials;
        $this->http = $http;
        $this->cache = $cache;
        $this->redactor = $redactor;
        // A client secret goes to this host. It is refused unless it is a bare https origin.
        $this->loginBaseUrl = Endpoint::normalise($loginBaseUrl, 'login_base_url');
    }

    /**
     * @return string A bearer token.
     * @throws GraphException
     */
    public function getToken()
    {
        $cached = $this->cache->get($this->getCacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->requestToken();
    }

    /**
     * Drop the cached token. Called after a 401, so the single retry uses a fresh one.
     *
     * @return void
     */
    public function invalidate()
    {
        $this->cache->delete($this->getCacheKey());
    }

    /**
     * @return string
     */
    public function getCacheKey()
    {
        return self::CACHE_PREFIX . $this->credentials->getCacheDiscriminator();
    }

    /**
     * @return string
     * @throws GraphException
     */
    private function requestToken()
    {
        $this->credentials->validate();

        $url = $this->loginBaseUrl . '/' . rawurlencode($this->credentials->getTenantId())
            . '/oauth2/v2.0/token';

        $parameters = array(
            'grant_type' => 'client_credentials',
            'client_id' => $this->credentials->getClientId(),
            'scope' => self::SCOPE,
        );

        if ($this->credentials->usesCertificate()) {
            $assertion = new ClientAssertion($this->credentials);
            $parameters['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
            $parameters['client_assertion'] = $assertion->build();
        } else {
            $parameters['client_secret'] = $this->credentials->getClientSecret();
        }

        try {
            $response = $this->http->post(
                $url,
                http_build_query($parameters, '', '&'),
                array('Content-Type' => 'application/x-www-form-urlencoded')
            );
        } catch (\RuntimeException $e) {
            throw new GraphException(
                'Missivus: could not reach the Microsoft token endpoint: '
                . $this->redactor->redact($e->getMessage())
            );
        }

        if (!$response->isSuccess()) {
            // The Entra error body is the single most useful thing for diagnosing a broken app
            // registration, so it is kept — after redaction.
            throw new GraphException(
                'Missivus: Microsoft rejected the token request',
                $response->getStatus(),
                $this->redactor->redactBody($response->getBody())
            );
        }

        $payload = $response->getJson();

        if (empty($payload['access_token'])) {
            throw new GraphException(
                'Missivus: the token response contained no access_token',
                $response->getStatus(),
                $this->redactor->redactBody($response->getBody())
            );
        }

        $token = (string) $payload['access_token'];
        $expiresIn = isset($payload['expires_in']) ? (int) $payload['expires_in'] : 3600;
        $ttl = $expiresIn - self::EXPIRY_MARGIN_SECONDS;

        // A token shorter-lived than the margin is not worth caching, but it is still usable now.
        if ($ttl > 0) {
            $this->cache->set($this->getCacheKey(), $token, $ttl);
        }

        return $token;
    }
}
