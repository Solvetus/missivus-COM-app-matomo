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

    public function testTheRefusalNeverRepeatsTheUserinfoBackAtTheOperator()
    {
        try {
            Endpoint::normalise('https://admin:hunter2-correct-horse@login.example.test', 'login_base_url');
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertStringNotContainsString('hunter2-correct-horse', $e->getMessage());
            $this->assertStringNotContainsString('admin:', $e->getMessage());
            $this->assertStringNotContainsString('@', $e->getMessage());
            $this->assertStringContainsString('login.example.test', $e->getMessage(), 'The host is safe, and useful');
            $this->assertStringContainsString('login_base_url', $e->getMessage(), 'So is the setting name');
        }
    }

    public function testTheRefusalNeverRepeatsAQueryStringBackAtTheOperator()
    {
        $urls = array(
            'https://login.example.test/?access_token=TOKEN-VALUE-SHOULD-NOT-APPEAR',
            'https://login.example.test/?client_secret=SECRET-VALUE-SHOULD-NOT-APPEAR',
            'https://login.example.test/?code=CODE-VALUE-SHOULD-NOT-APPEAR',
        );

        foreach ($urls as $url) {
            try {
                Endpoint::normalise($url, 'login_base_url');
                $this->fail('Expected a GraphException for ' . $url);
            } catch (GraphException $e) {
                $this->assertStringNotContainsString('SHOULD-NOT-APPEAR', $e->getMessage());
                $this->assertStringNotContainsString('?', $e->getMessage());
                $this->assertStringNotContainsString('=', $e->getMessage());
                $this->assertStringContainsString('login.example.test', $e->getMessage());
            }
        }
    }

    public function testTheRefusalNeverRepeatsAFragmentBackAtTheOperator()
    {
        try {
            Endpoint::normalise('https://graph.example.test/v1.0#FRAGMENT-SHOULD-NOT-APPEAR', 'graph_base_url');
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertStringNotContainsString('FRAGMENT-SHOULD-NOT-APPEAR', $e->getMessage());
            $this->assertStringNotContainsString('#', $e->getMessage());
            $this->assertStringContainsString('https://graph.example.test/v1.0', $e->getMessage());
        }
    }

    public function testAnHttpUrlWithCredentialsIsRefusedWithoutNamingThem()
    {
        // The scheme check fires first, so this is the one refusal built from a URL we have already
        // decided is hostile. It still may not echo the userinfo.
        try {
            Endpoint::normalise('http://admin:hunter2-correct-horse@login.evil.test', 'login_base_url');
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertStringNotContainsString('hunter2-correct-horse', $e->getMessage());
            $this->assertSame(
                'Missivus: login_base_url must be an https:// URL.'
                . ' Refusing to send credentials to http://login.evil.test',
                $e->getMessage()
            );
        }
    }

    public function testSomethingUnparseableIsRefusedWithoutBeingEchoedAtAll()
    {
        // No structure means no part of it can be called safe, so none of it is repeated.
        try {
            Endpoint::normalise('WHOLE-THING-IS-A-SECRET', 'graph_base_url');
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertStringNotContainsString('WHOLE-THING-IS-A-SECRET', $e->getMessage());
            $this->assertStringContainsString('not a valid endpoint URL', $e->getMessage());
        }
    }

    public function testAnInvalidHostIsRefusedWithoutBeingEchoed()
    {
        try {
            Endpoint::normalise('https://gr@ph_HOST-NOT-ECHOED.example.test', 'graph_base_url');
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertStringNotContainsString('HOST-NOT-ECHOED', $e->getMessage());
        }
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
