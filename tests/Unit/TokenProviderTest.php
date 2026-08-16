<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-COM-app-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Missivus\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\Missivus\tests\Framework\ArrayTokenCache;
use Piwik\Plugins\Missivus\tests\Framework\FakeHttpClient;
use Piwik\Plugins\Missivus\tests\Framework\TestCertificate;
use Solvetus\Missivus\Auth\ClientAssertion;
use Solvetus\Missivus\Auth\Credentials;
use Solvetus\Missivus\Auth\TokenProvider;
use Solvetus\Missivus\Exception\GraphException;
use Solvetus\Missivus\Redactor;

require_once __DIR__ . '/../Framework/Doubles.php';

/**
 * @group Missivus
 */
class TokenProviderTest extends TestCase
{
    /** @var FakeHttpClient */
    private $http;

    /** @var ArrayTokenCache */
    private $cache;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->cache = new ArrayTokenCache();
    }

    public function testClientSecretTokenRequestUsesTheClientCredentialsGrant()
    {
        $this->http->queueJson(200, array('access_token' => 'token-abc', 'expires_in' => 3600));

        $token = $this->providerForSecret()->getToken();

        $this->assertSame('token-abc', $token);
        $this->assertSame(1, $this->http->count());

        $request = $this->http->request(0);
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://login.example.test/tenant-id/oauth2/v2.0/token', $request['url']);
        $this->assertSame('application/x-www-form-urlencoded', $request['headers']['Content-Type']);

        $form = $this->http->requestForm(0);
        $this->assertSame('client_credentials', $form['grant_type']);
        $this->assertSame('client-id', $form['client_id']);
        $this->assertSame('super-secret-value', $form['client_secret']);
        $this->assertSame('https://graph.microsoft.com/.default', $form['scope']);
        $this->assertArrayNotHasKey('client_assertion', $form);
    }

    public function testTokenIsCachedSoASecondCallMakesNoRequest()
    {
        $this->http->queueJson(200, array('access_token' => 'token-abc', 'expires_in' => 3600));

        $provider = $this->providerForSecret();

        $this->assertSame('token-abc', $provider->getToken());
        $this->assertSame('token-abc', $provider->getToken());
        $this->assertSame(1, $this->http->count(), 'The second call must come from the cache');
        $this->assertSame(1, $this->cache->writes);
    }

    public function testCachedTokenExpiresFiveMinutesEarlyAndIsThenRefreshed()
    {
        $this->http->queueJson(200, array('access_token' => 'first', 'expires_in' => 3600));
        $this->http->queueJson(200, array('access_token' => 'second', 'expires_in' => 3600));

        $provider = $this->providerForSecret();

        $this->assertSame('first', $provider->getToken());

        // 3600 - 300 margin = 3300 seconds of usable life.
        $this->assertSame(3300, $this->cache->ttlOf($provider->getCacheKey()));

        $this->cache->advance(3299);
        $this->assertSame('first', $provider->getToken(), 'Still inside the cached window');
        $this->assertSame(1, $this->http->count());

        $this->cache->advance(2);
        $this->assertSame('second', $provider->getToken(), 'Past the margin, a fresh token is fetched');
        $this->assertSame(2, $this->http->count());
    }

    public function testAVeryShortLivedTokenIsUsedButNotCached()
    {
        $this->http->queueJson(200, array('access_token' => 'brief', 'expires_in' => 60));

        $this->assertSame('brief', $this->providerForSecret()->getToken());
        $this->assertSame(0, $this->cache->writes, 'A token shorter than the safety margin must not be cached');
    }

    public function testCertificateTokenRequestSendsASignedClientAssertion()
    {
        $certificate = new TestCertificate();
        $this->http->queueJson(200, array('access_token' => 'token-cert', 'expires_in' => 3600));

        $credentials = (new Credentials('tenant-id', 'client-id', Credentials::METHOD_CERTIFICATE))
            ->withCertificate($certificate->path);

        $token = $this->provider($credentials)->getToken();

        $this->assertSame('token-cert', $token);

        $form = $this->http->requestForm(0);
        $this->assertSame(
            'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            $form['client_assertion_type']
        );
        $this->assertArrayNotHasKey('client_secret', $form);

        $parts = explode('.', $form['client_assertion']);
        $this->assertCount(3, $parts, 'A client assertion is a three-part JWT');

        $header = json_decode(self::base64UrlDecode($parts[0]), true);
        $this->assertSame('PS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);
        $this->assertArrayHasKey('x5t#S256', $header);

        $claims = json_decode(self::base64UrlDecode($parts[1]), true);
        $this->assertSame('https://login.microsoftonline.com/tenant-id/oauth2/v2.0/token', $claims['aud']);
        $this->assertSame('client-id', $claims['iss']);
        $this->assertSame('client-id', $claims['sub']);
        $this->assertArrayHasKey('jti', $claims);
        $this->assertArrayHasKey('nbf', $claims);
        $this->assertSame($claims['nbf'] + ClientAssertion::LIFETIME_SECONDS, $claims['exp']);
    }

    public function testAnEntraRejectionThrowsAndKeepsTheErrorBody()
    {
        $this->http->queueRaw(401, '{"error":"invalid_client","error_description":"AADSTS7000215"}');

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage('AADSTS7000215');

        $this->providerForSecret()->getToken();
    }

    public function testATokenResponseWithoutAnAccessTokenIsAFailure()
    {
        $this->http->queueJson(200, array('token_type' => 'Bearer'));

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage('no access_token');

        $this->providerForSecret()->getToken();
    }

    public function testAnUnreachableEndpointIsReportedRatherThanSwallowed()
    {
        $this->http->failTransport('Could not resolve host');

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage('could not reach the Microsoft token endpoint');

        $this->providerForSecret()->getToken();
    }

    public function testMissingConfigurationFailsBeforeAnyRequestIsMade()
    {
        $credentials = (new Credentials('', 'client-id', Credentials::METHOD_SECRET))->withClientSecret('x');

        try {
            $this->provider($credentials)->getToken();
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertStringContainsString('tenant ID', $e->getMessage());
            $this->assertSame(0, $this->http->count(), 'Nothing should have been sent');
        }
    }

    /**
     * @return TokenProvider
     */
    private function providerForSecret()
    {
        $credentials = (new Credentials('tenant-id', 'client-id', Credentials::METHOD_SECRET))
            ->withClientSecret('super-secret-value');

        return $this->provider($credentials);
    }

    /**
     * @param Credentials $credentials
     * @return TokenProvider
     */
    private function provider(Credentials $credentials)
    {
        return new TokenProvider(
            $credentials,
            $this->http,
            $this->cache,
            new Redactor($credentials->getSecretLiterals()),
            'https://login.example.test'
        );
    }

    /**
     * @param string $value
     * @return string
     */
    private static function base64UrlDecode($value)
    {
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4));
    }
}
