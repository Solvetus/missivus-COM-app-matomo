<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Missivus\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\Missivus\tests\Framework\ArrayTokenCache;
use Piwik\Plugins\Missivus\tests\Framework\FakeHttpClient;
use Solvetus\Missivus\Auth\Credentials;
use Solvetus\Missivus\Auth\TokenProvider;
use Solvetus\Missivus\Endpoint;
use Solvetus\Missivus\Exception\GraphException;
use Solvetus\Missivus\GraphMailer;
use Solvetus\Missivus\Redactor;

require_once __DIR__ . '/../Framework/Doubles.php';

/**
 * The base URLs exist so sovereign clouds and this test suite work. They are also the only setting
 * that can aim a client secret or a bearer token at a host of somebody else's choosing, so these
 * tests hold the rule that anything but a bare https origin is refused before a request is built.
 *
 * @group Missivus
 */
class EndpointTest extends TestCase
{
    public function testABareHttpsOriginIsAcceptedAndLosesItsTrailingSlash()
    {
        $this->assertSame(
            'https://graph.microsoft.com',
            Endpoint::normalise('https://graph.microsoft.com/', 'graph_base_url')
        );
    }

    public function testAPortAndPathSurvive()
    {
        $this->assertSame(
            'https://graph.example.test:8443/beta',
            Endpoint::normalise('https://graph.example.test:8443/beta/', 'graph_base_url')
        );
    }

    public function testAnHttpUrlIsRefused()
    {
        try {
            Endpoint::normalise('http://login.example.test', 'login_base_url');
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertStringContainsString('https', $e->getMessage());
            $this->assertStringContainsString('login_base_url', $e->getMessage());
        }
    }

    public function testAUrlCarryingCredentialsOrAQueryStringIsRefused()
    {
        $this->expectException(GraphException::class);

        Endpoint::normalise('https://user:pass@login.example.test', 'login_base_url');
    }

    public function testSomethingThatIsNotAUrlAtAllIsRefused()
    {
        $this->expectException(GraphException::class);

        Endpoint::normalise('login.example.test', 'login_base_url');
    }

    public function testAnEmptyBaseUrlIsRefused()
    {
        $this->expectException(GraphException::class);

        Endpoint::normalise('', 'graph_base_url');
    }

    public function testTheTokenProviderRefusesANonHttpsLoginHostBeforeAnyRequest()
    {
        $http = new FakeHttpClient();

        try {
            new TokenProvider(
                (new Credentials('tenant-id', 'client-id', Credentials::METHOD_SECRET))
                    ->withClientSecret('super-secret-value'),
                $http,
                new ArrayTokenCache(),
                new Redactor(),
                'http://login.evil.test'
            );
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertSame(0, $http->count(), 'The secret must never leave the process');
        }
    }

    public function testTheMailerRefusesANonHttpsGraphHostBeforeAnyRequest()
    {
        $http = new FakeHttpClient();

        $tokens = new TokenProvider(
            (new Credentials('tenant-id', 'client-id', Credentials::METHOD_SECRET))
                ->withClientSecret('super-secret-value'),
            $http,
            new ArrayTokenCache(),
            new Redactor(),
            'https://login.example.test'
        );

        $this->expectException(GraphException::class);

        new GraphMailer($tokens, $http, new Redactor(), 'noreply@example.com', false, 'http://graph.evil.test');
    }
}
