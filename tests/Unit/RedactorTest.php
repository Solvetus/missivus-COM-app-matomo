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
use Solvetus\Missivus\Auth\Credentials;
use Solvetus\Missivus\Auth\TokenProvider;
use Solvetus\Missivus\Exception\GraphException;
use Solvetus\Missivus\Redactor;

require_once __DIR__ . '/../Framework/Doubles.php';

/**
 * The house rule is that a secret never reaches a log file. These are the tests that hold it.
 *
 * @group Missivus
 */
class RedactorTest extends TestCase
{
    public function testAKnownSecretIsBlankedWhereverItAppears()
    {
        $redactor = new Redactor(array('super-secret-value'));

        $result = $redactor->redact('sending with super-secret-value now');

        $this->assertStringNotContainsString('super-secret-value', $result);
        $this->assertStringContainsString(Redactor::MASK, $result);
    }

    public function testAnAccessTokenInAJsonBodyIsBlankedEvenThoughWeNeverHeldIt()
    {
        $redactor = new Redactor();

        $result = $redactor->redact('{"access_token":"eyJ0eXAiOiJKV1QifQ.payload.signature","expires_in":3600}');

        $this->assertStringNotContainsString('eyJ0eXAiOiJKV1QifQ', $result);
        $this->assertStringContainsString('expires_in', $result, 'Harmless fields survive');
    }

    public function testFormEncodedCredentialsAreBlanked()
    {
        $redactor = new Redactor();

        $result = $redactor->redact('grant_type=client_credentials&client_secret=abcd1234efgh&scope=.default');

        $this->assertStringNotContainsString('abcd1234efgh', $result);
        $this->assertStringContainsString('grant_type=client_credentials', $result);
        $this->assertStringContainsString('scope=.default', $result);
    }

    public function testABearerHeaderIsBlanked()
    {
        $redactor = new Redactor();

        $result = $redactor->redact('Authorization: Bearer abcdefghijklmnop1234567890');

        $this->assertStringNotContainsString('abcdefghijklmnop1234567890', $result);
    }

    public function testABareJwtIsBlanked()
    {
        $redactor = new Redactor();

        $result = $redactor->redact('assertion was eyJhbGciOiJQUzI1NiJ9.eyJpc3MiOiJ4In0.c2lnbmF0dXJl done');

        $this->assertStringNotContainsString('eyJhbGciOiJQUzI1NiJ9', $result);
        $this->assertStringContainsString('assertion was', $result);
    }

    public function testAVeryLongBodyIsTruncatedBeforeBeingLogged()
    {
        $redactor = new Redactor();

        $result = $redactor->redactBody(str_repeat('x', 5000));

        $this->assertStringContainsString('(truncated)', $result);
        $this->assertTrue(strlen($result) < 2100);
    }

    public function testAnEntraErrorEchoingTheSecretDoesNotLeakItThroughTheException()
    {
        $http = new FakeHttpClient();
        $http->queueRaw(400, '{"error":"invalid_client","error_description":"secret super-secret-value rejected"}');

        $credentials = (new Credentials('tenant-id', 'client-id', Credentials::METHOD_SECRET))
            ->withClientSecret('super-secret-value');

        $provider = new TokenProvider(
            $credentials,
            $http,
            new ArrayTokenCache(),
            new Redactor($credentials->getSecretLiterals()),
            'https://login.example.test'
        );

        try {
            $provider->getToken();
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertStringNotContainsString('super-secret-value', $e->getMessage());
            $this->assertStringContainsString('invalid_client', $e->getMessage(), 'The useful part survives');
        }
    }

    public function testCredentialsNeverRenderTheirSecret()
    {
        $credentials = (new Credentials('tenant-id', 'client-id', Credentials::METHOD_SECRET))
            ->withClientSecret('super-secret-value');

        $this->assertStringNotContainsString('super-secret-value', (string) $credentials);
        $this->assertStringNotContainsString('super-secret-value', print_r($credentials->__debugInfo(), true));
        $this->assertStringContainsString('tenant-id', (string) $credentials);
    }
}
