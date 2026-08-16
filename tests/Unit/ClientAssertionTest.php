<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-COM-app-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Missivus\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\Missivus\tests\Framework\TestCertificate;
use Solvetus\Missivus\Auth\ClientAssertion;
use Solvetus\Missivus\Auth\Credentials;
use Solvetus\Missivus\Exception\GraphException;

require_once __DIR__ . '/../Framework/Doubles.php';

/**
 * PHP cannot produce a PSS signature on its own, so ClientAssertion implements EMSA-PSS by hand.
 * Checking that with our own code would prove nothing, so the signature is handed to the openssl
 * command-line tool — a genuinely independent implementation — and it has to say "Verified OK".
 *
 * @group Missivus
 */
class ClientAssertionTest extends TestCase
{
    /** @var TestCertificate */
    private $certificate;

    protected function setUp(): void
    {
        $this->certificate = new TestCertificate();
    }

    public function testPs256SignatureVerifiesWithTheOpensslCommandLineTool()
    {
        $jwt = $this->buildAssertion(ClientAssertion::ALG_PS256);
        $parts = explode('.', $jwt);

        $signingInput = $parts[0] . '.' . $parts[1];
        $signature = self::base64UrlDecode($parts[2]);

        $this->assertSame(256, strlen($signature), 'A 2048-bit RSA signature is 256 bytes');

        $inputFile = tempnam(sys_get_temp_dir(), 'missivus-input-');
        $signatureFile = tempnam(sys_get_temp_dir(), 'missivus-sig-');
        file_put_contents($inputFile, $signingInput);
        file_put_contents($signatureFile, $signature);

        $command = 'openssl dgst -sha256'
            . ' -sigopt rsa_padding_mode:pss'
            . ' -sigopt rsa_pss_saltlen:-1'
            . ' -verify ' . escapeshellarg($this->certificate->publicKeyPath)
            . ' -signature ' . escapeshellarg($signatureFile)
            . ' ' . escapeshellarg($inputFile)
            . ' 2>&1';

        $output = array();
        $status = 0;
        exec($command, $output, $status);

        @unlink($inputFile);
        @unlink($signatureFile);

        $this->assertSame(0, $status, 'openssl exited non-zero: ' . implode("\n", $output));
        $this->assertStringContainsString('Verified OK', implode("\n", $output));
    }

    public function testPs256HeaderCarriesTheSha256Thumbprint()
    {
        $header = $this->headerOf($this->buildAssertion(ClientAssertion::ALG_PS256));

        $this->assertSame('PS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);
        $this->assertArrayHasKey('x5t#S256', $header);
        $this->assertArrayNotHasKey('x5t', $header);

        // base64url of a SHA-256 digest: 32 bytes, unpadded.
        $this->assertSame(43, strlen($header['x5t#S256']));
        $this->assertSame(
            self::base64UrlEncode(hash('sha256', $this->certificateDer(), true)),
            $header['x5t#S256']
        );
    }

    public function testRs256EscapeHatchProducesAVerifiableSignatureAndAnX5tHeader()
    {
        $jwt = $this->buildAssertion(ClientAssertion::ALG_RS256);
        $parts = explode('.', $jwt);
        $header = $this->headerOf($jwt);

        $this->assertSame('RS256', $header['alg']);
        $this->assertArrayHasKey('x5t', $header);
        $this->assertArrayNotHasKey('x5t#S256', $header);
        $this->assertSame(
            self::base64UrlEncode(hash('sha1', $this->certificateDer(), true)),
            $header['x5t']
        );

        // RS256 is plain PKCS#1 v1.5, which openssl_verify does understand.
        $verified = openssl_verify(
            $parts[0] . '.' . $parts[1],
            self::base64UrlDecode($parts[2]),
            file_get_contents($this->certificate->publicKeyPath),
            OPENSSL_ALGO_SHA256
        );

        $this->assertSame(1, $verified);
    }

    public function testEachAssertionGetsItsOwnJti()
    {
        $first = $this->claimsOf($this->buildAssertion(ClientAssertion::ALG_PS256));
        $second = $this->claimsOf($this->buildAssertion(ClientAssertion::ALG_PS256));

        $this->assertNotEquals($first['jti'], $second['jti']);
        // RFC 4122 shape: 8-4-4-4-12.
        $this->assertSame(36, strlen($first['jti']));
    }

    public function testTheClockIsInjectableSoClaimsAreDeterministic()
    {
        $claims = $this->claimsOf($this->buildAssertion(ClientAssertion::ALG_PS256, 1700000000));

        $this->assertSame(1700000000, $claims['iat']);
        $this->assertSame(1700000000, $claims['nbf']);
        $this->assertSame(1700000300, $claims['exp']);
    }

    public function testAMissingCertificateNamesThePathAndNothingElse()
    {
        $credentials = (new Credentials('tenant-id', 'client-id', Credentials::METHOD_CERTIFICATE))
            ->withCertificate('/nonexistent/missivus.pem');

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage('/nonexistent/missivus.pem');

        (new ClientAssertion($credentials))->build();
    }

    public function testAPassphraseProtectedKeyIsAccepted()
    {
        $protected = new TestCertificate('correct horse battery staple');

        $credentials = (new Credentials('tenant-id', 'client-id', Credentials::METHOD_CERTIFICATE))
            ->withCertificate($protected->path, 'correct horse battery staple');

        $jwt = (new ClientAssertion($credentials))->build();

        $this->assertCount(3, explode('.', $jwt));
    }

    public function testTheWrongPassphraseFailsWithoutEchoingIt()
    {
        $protected = new TestCertificate('correct horse battery staple');

        $credentials = (new Credentials('tenant-id', 'client-id', Credentials::METHOD_CERTIFICATE))
            ->withCertificate($protected->path, 'wrong passphrase entirely');

        try {
            (new ClientAssertion($credentials))->build();
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertStringContainsString('could not be loaded', $e->getMessage());
            $this->assertStringNotContainsString('wrong passphrase entirely', $e->getMessage());
        }
    }

    /**
     * @param string   $algorithm
     * @param int|null $now
     * @return string
     */
    private function buildAssertion($algorithm, $now = null)
    {
        $credentials = (new Credentials('tenant-id', 'client-id', Credentials::METHOD_CERTIFICATE))
            ->withCertificate($this->certificate->path, '', $algorithm);

        return (new ClientAssertion($credentials))->build($now);
    }

    /**
     * @param string $jwt
     * @return array
     */
    private function headerOf($jwt)
    {
        $parts = explode('.', $jwt);

        return json_decode(self::base64UrlDecode($parts[0]), true);
    }

    /**
     * @param string $jwt
     * @return array
     */
    private function claimsOf($jwt)
    {
        $parts = explode('.', $jwt);

        return json_decode(self::base64UrlDecode($parts[1]), true);
    }

    /**
     * @return string
     */
    private function certificateDer()
    {
        $pem = file_get_contents($this->certificate->path);
        $exported = '';
        openssl_x509_export(openssl_x509_read($pem), $exported);

        return base64_decode(preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $exported));
    }

    private static function base64UrlEncode($binary)
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($value)
    {
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4));
    }
}
