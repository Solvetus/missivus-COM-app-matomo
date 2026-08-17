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
 * Builds the signed JWT that stands in for a client secret in the client-credentials grant
 * (OpenID Connect's private_key_jwt).
 *
 * Microsoft's current certificate-credentials reference specifies alg = PS256 (RSASSA-PSS,
 * SHA-256) with an x5t#S256 header. PHP's openssl_sign() cannot produce PSS — it only does
 * PKCS#1 v1.5 — so EMSA-PSS-ENCODE (RFC 8017 §9.1.1) is implemented here and the raw RSA
 * primitive applied via openssl_private_encrypt() with OPENSSL_NO_PADDING. The result is
 * verified against the openssl CLI in the test suite, which is an independent implementation.
 *
 * RS256 is retained as a config-only escape hatch: Entra still accepts it and it is what most
 * SDKs sent historically. See docs/index.md — it is for the case where PS256 is rejected.
 */
class ClientAssertion
{
    const ALG_PS256 = 'PS256';
    const ALG_RS256 = 'RS256';

    /** Microsoft's guidance is 5–10 minutes at most. */
    const LIFETIME_SECONDS = 300;

    /** @var Credentials */
    private $credentials;

    /**
     * @param Credentials $credentials
     */
    public function __construct(Credentials $credentials)
    {
        $this->credentials = $credentials;
    }

    /**
     * @param int|null $now Unix timestamp; injectable so tests are deterministic.
     * @return string The encoded JWT.
     * @throws GraphException
     */
    public function build($now = null)
    {
        $now = $now === null ? time() : (int) $now;

        $pem = @file_get_contents($this->credentials->getCertificatePath());
        if ($pem === false || $pem === '') {
            throw new GraphException(
                'Missivus: could not read the certificate at ' . $this->credentials->getCertificatePath()
            );
        }

        $privateKey = $this->loadPrivateKey($pem);
        $der = $this->certificateDer($pem);
        $algorithm = $this->credentials->getCertificateAlgorithm();

        // x5t#S256 is the SHA-256 thumbprint Microsoft's current reference asks for; x5t is the
        // older SHA-1 form, kept alongside RS256 for the escape-hatch path.
        if ($algorithm === self::ALG_RS256) {
            $header = array(
                'alg' => self::ALG_RS256,
                'typ' => 'JWT',
                'x5t' => self::base64UrlEncode(hash('sha1', $der, true)),
            );
        } else {
            $header = array(
                'alg' => self::ALG_PS256,
                'typ' => 'JWT',
                'x5t#S256' => self::base64UrlEncode(hash('sha256', $der, true)),
            );
        }

        $tenantId = $this->credentials->getTenantId();
        $clientId = $this->credentials->getClientId();

        $claims = array(
            'aud' => 'https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/token',
            'iss' => $clientId,
            'sub' => $clientId,
            'jti' => self::uuidV4(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + self::LIFETIME_SECONDS,
        );

        $signingInput = self::base64UrlEncode(self::encodeJson($header))
            . '.' . self::base64UrlEncode(self::encodeJson($claims));

        $signature = $algorithm === self::ALG_RS256
            ? $this->signPkcs1($signingInput, $privateKey)
            : $this->signPss($signingInput, $privateKey);

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    /**
     * @param string $pem
     * @return resource|\OpenSSLAsymmetricKey
     * @throws GraphException
     */
    private function loadPrivateKey($pem)
    {
        $key = @openssl_pkey_get_private($pem, $this->credentials->getCertificatePassphrase());

        if ($key === false) {
            // Never echo openssl_error_string() here: with a wrong passphrase it can quote input.
            throw new GraphException(
                'Missivus: the private key at ' . $this->credentials->getCertificatePath()
                . ' could not be loaded. Check the file contains a PRIVATE KEY block and that the'
                . ' passphrase is correct.'
            );
        }

        $details = @openssl_pkey_get_details($key);
        if (!is_array($details) || !isset($details['type']) || $details['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw new GraphException(
                'Missivus: the key at ' . $this->credentials->getCertificatePath()
                . ' is not an RSA key. Microsoft Entra client assertions require RSA.'
            );
        }

        return $key;
    }

    /**
     * The certificate's DER encoding — what both thumbprint forms are computed over.
     *
     * @param string $pem
     * @return string
     * @throws GraphException
     */
    private function certificateDer($pem)
    {
        $certificate = @openssl_x509_read($pem);

        if ($certificate === false) {
            throw new GraphException(
                'Missivus: no certificate found in ' . $this->credentials->getCertificatePath()
                . '. The file must contain the CERTIFICATE block as well as the private key.'
            );
        }

        $exported = '';
        if (!@openssl_x509_export($certificate, $exported)) {
            throw new GraphException(
                'Missivus: the certificate in ' . $this->credentials->getCertificatePath()
                . ' could not be re-encoded.'
            );
        }

        $base64 = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $exported);
        $der = base64_decode((string) $base64, true);

        if ($der === false || $der === '') {
            throw new GraphException(
                'Missivus: the certificate in ' . $this->credentials->getCertificatePath()
                . ' is not valid base64.'
            );
        }

        return $der;
    }

    /**
     * RSASSA-PKCS1-v1_5 with SHA-256 — the RS256 escape hatch.
     *
     * @param string                          $data
     * @param resource|\OpenSSLAsymmetricKey  $privateKey
     * @return string
     * @throws GraphException
     */
    private function signPkcs1($data, $privateKey)
    {
        $signature = '';

        if (!@openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new GraphException('Missivus: RS256 signing of the client assertion failed.');
        }

        return $signature;
    }

    /**
     * RSASSA-PSS with SHA-256 and a salt the length of the hash — RFC 8017 §9.1.1 followed by the
     * raw RSA primitive.
     *
     * @param string                          $data
     * @param resource|\OpenSSLAsymmetricKey  $privateKey
     * @return string
     * @throws GraphException
     */
    private function signPss($data, $privateKey)
    {
        $details = openssl_pkey_get_details($privateKey);
        $modBits = (int) $details['bits'];

        $hLen = 32;
        $sLen = 32;
        $emBits = $modBits - 1;
        $emLen = (int) ceil($emBits / 8);

        if ($emLen < $hLen + $sLen + 2) {
            throw new GraphException(
                'Missivus: the RSA key is too small for PS256 (needs at least 2048 bits).'
            );
        }

        $mHash = hash('sha256', $data, true);
        $salt = random_bytes($sLen);

        // M' = 8 zero bytes || mHash || salt
        $h = hash('sha256', str_repeat("\x00", 8) . $mHash . $salt, true);

        // DB = PS || 0x01 || salt
        $db = str_repeat("\x00", $emLen - $sLen - $hLen - 2) . "\x01" . $salt;
        $maskedDb = $db ^ $this->mgf1($h, $emLen - $hLen - 1);

        // Clear the leftmost 8*emLen - emBits bits so EM is guaranteed below the modulus.
        $bitsToClear = 8 * $emLen - $emBits;
        if ($bitsToClear > 0) {
            $maskedDb[0] = chr(ord($maskedDb[0]) & (0xFF >> $bitsToClear));
        }

        $em = $maskedDb . $h . "\xbc";

        // openssl_private_encrypt with NO_PADDING wants exactly the modulus length.
        $k = (int) ceil($modBits / 8);
        if (strlen($em) < $k) {
            $em = str_repeat("\x00", $k - strlen($em)) . $em;
        }

        $signature = '';
        if (!@openssl_private_encrypt($em, $signature, $privateKey, OPENSSL_NO_PADDING)) {
            throw new GraphException('Missivus: PS256 signing of the client assertion failed.');
        }

        return $signature;
    }

    /**
     * MGF1 with SHA-256 (RFC 8017 §B.2.1).
     *
     * @param string $seed
     * @param int    $length
     * @return string
     */
    private function mgf1($seed, $length)
    {
        $output = '';

        for ($counter = 0; strlen($output) < $length; $counter++) {
            $output .= hash('sha256', $seed . pack('N', $counter), true);
        }

        return substr($output, 0, $length);
    }

    /**
     * @param array $value
     * @return string
     */
    private static function encodeJson(array $value)
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param string $binary
     * @return string
     */
    public static function base64UrlEncode($binary)
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /**
     * @return string
     */
    private static function uuidV4()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
