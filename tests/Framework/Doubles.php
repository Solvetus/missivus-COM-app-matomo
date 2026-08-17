<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Missivus\tests\Framework;

use Piwik\Plugins\Missivus\Configuration\ConfigurationInterface;
use Solvetus\Missivus\Auth\Credentials;
use Solvetus\Missivus\Contract\HttpClientInterface;
use Solvetus\Missivus\Contract\HttpResponse;
use Solvetus\Missivus\Contract\LoggerInterface;
use Solvetus\Missivus\Contract\TokenCacheInterface;

require_once __DIR__ . '/../../libs/autoload.php';
require_once __DIR__ . '/../../Configuration/ConfigurationInterface.php';

/**
 * The mocked Graph endpoint. Records every request and answers from a scripted queue, so a test
 * asserts on the exact bytes Missivus would have put on the wire.
 */
class FakeHttpClient implements HttpClientInterface
{
    /** @var array Each: ['method','url','body','headers','timeout'] */
    public $requests = array();

    /** @var array Queue of HttpResponse, or callables taking the request array. */
    private $responses = array();

    /** @var HttpResponse|null Used once the queue is empty. */
    private $fallback;

    /** @var \RuntimeException|null Thrown instead of answering, to simulate a dead network. */
    private $transportFailure;

    /**
     * @param HttpResponse|callable $response
     * @return $this
     */
    public function queue($response)
    {
        $this->responses[] = $response;

        return $this;
    }

    /**
     * @param int    $status
     * @param array  $json
     * @param array  $headers
     * @return $this
     */
    public function queueJson($status, array $json = array(), array $headers = array())
    {
        return $this->queue(new HttpResponse($status, json_encode($json), $headers));
    }

    /**
     * @param int    $status
     * @param string $body
     * @return $this
     */
    public function queueRaw($status, $body = '')
    {
        return $this->queue(new HttpResponse($status, $body));
    }

    /**
     * @param HttpResponse $response
     * @return $this
     */
    public function always(HttpResponse $response)
    {
        $this->fallback = $response;

        return $this;
    }

    /**
     * @param string $message
     * @return $this
     */
    public function failTransport($message)
    {
        $this->transportFailure = new \RuntimeException($message);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function post($url, $body, array $headers = array(), $timeout = 30)
    {
        return $this->record('POST', $url, $body, $headers, $timeout);
    }

    /**
     * {@inheritdoc}
     */
    public function put($url, $body, array $headers = array(), $timeout = 60)
    {
        return $this->record('PUT', $url, $body, $headers, $timeout);
    }

    /**
     * @return HttpResponse
     */
    private function record($method, $url, $body, array $headers, $timeout)
    {
        $request = array(
            'method' => $method,
            'url' => $url,
            'body' => $body,
            'headers' => $headers,
            'timeout' => $timeout,
        );

        $this->requests[] = $request;

        if ($this->transportFailure !== null) {
            throw $this->transportFailure;
        }

        if (!empty($this->responses)) {
            $next = array_shift($this->responses);

            return is_callable($next) ? call_user_func($next, $request) : $next;
        }

        if ($this->fallback !== null) {
            return $this->fallback;
        }

        throw new \RuntimeException('FakeHttpClient has no scripted response for ' . $method . ' ' . $url);
    }

    /**
     * @param int $index
     * @return array
     */
    public function request($index)
    {
        if (!isset($this->requests[$index])) {
            throw new \RuntimeException('No request at index ' . $index . ' (there are ' . count($this->requests) . ')');
        }

        return $this->requests[$index];
    }

    /**
     * @param int $index
     * @return array The request body decoded as JSON.
     */
    public function requestJson($index)
    {
        $decoded = json_decode($this->request($index)['body'], true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param int $index
     * @return array The request body parsed as a form-encoded string.
     */
    public function requestForm($index)
    {
        $parsed = array();
        parse_str($this->request($index)['body'], $parsed);

        return $parsed;
    }

    /**
     * @return int
     */
    public function count()
    {
        return count($this->requests);
    }

    /**
     * @return array URLs in the order they were called.
     */
    public function urls()
    {
        $urls = array();

        foreach ($this->requests as $request) {
            $urls[] = $request['url'];
        }

        return $urls;
    }
}

/**
 * An in-memory token cache with an injectable clock, so expiry is tested without sleeping.
 */
class ArrayTokenCache implements TokenCacheInterface
{
    /** @var array key => ['value' => string, 'expires' => int] */
    private $entries = array();

    /** @var int */
    public $now = 1000000;

    /** @var int How many times set() was called — proves a token was actually cached. */
    public $writes = 0;

    public function get($key)
    {
        if (!isset($this->entries[$key])) {
            return null;
        }

        if ($this->entries[$key]['expires'] <= $this->now) {
            unset($this->entries[$key]);

            return null;
        }

        return $this->entries[$key]['value'];
    }

    public function set($key, $value, $ttlSeconds)
    {
        $this->writes++;
        $this->entries[$key] = array('value' => $value, 'expires' => $this->now + (int) $ttlSeconds);
    }

    public function delete($key)
    {
        unset($this->entries[$key]);
    }

    /**
     * @param int $seconds
     * @return void
     */
    public function advance($seconds)
    {
        $this->now += (int) $seconds;
    }

    /**
     * @param string $key
     * @return int
     */
    public function ttlOf($key)
    {
        return isset($this->entries[$key]) ? $this->entries[$key]['expires'] - $this->now : 0;
    }
}

/**
 * Captures log lines so tests can assert both that a failure was reported and that no secret
 * survived into it.
 */
class RecordingLogger implements LoggerInterface
{
    public $errors = array();
    public $warnings = array();
    public $infos = array();

    public function error($message)
    {
        $this->errors[] = $message;
    }

    public function warning($message)
    {
        $this->warnings[] = $message;
    }

    public function info($message)
    {
        $this->infos[] = $message;
    }

    /**
     * @return string Everything logged, for a single "does this leak?" assertion.
     */
    public function everything()
    {
        return implode("\n", array_merge($this->errors, $this->warnings, $this->infos));
    }
}

/**
 * A configuration built from plain values, so the transport's own behaviour can be exercised
 * without a Matomo installation.
 */
class FakeConfiguration implements ConfigurationInterface
{
    public $enabled = true;
    public $fallback = false;
    public $saveToSentItems = false;
    public $sender = 'noreply@example.com';
    public $graphBaseUrl = 'https://graph.example.test';
    public $loginBaseUrl = 'https://login.example.test';
    public $problem = '';

    /** @var Credentials|null */
    public $credentials;

    public function __construct()
    {
        $credentials = new Credentials('tenant-id', 'client-id', Credentials::METHOD_SECRET);
        $this->credentials = $credentials->withClientSecret('super-secret-value');
    }

    public function isEnabled()
    {
        return $this->enabled;
    }

    public function shouldFallBackToDefaultTransport()
    {
        return $this->fallback;
    }

    public function shouldSaveToSentItems()
    {
        return $this->saveToSentItems;
    }

    public function getSenderMailbox()
    {
        return $this->sender;
    }

    public function getGraphBaseUrl()
    {
        return $this->graphBaseUrl;
    }

    public function getLoginBaseUrl()
    {
        return $this->loginBaseUrl;
    }

    public function getCredentials()
    {
        return $this->credentials;
    }

    public function getConfigurationProblem()
    {
        return $this->problem;
    }
}

/**
 * Generates a throwaway RSA key and self-signed certificate for the certificate-auth tests.
 * Nothing here is or ever becomes a real credential.
 */
class TestCertificate
{
    /** @var string */
    public $path;

    /** @var string */
    public $publicKeyPath;

    /**
     * @param string $passphrase
     */
    public function __construct($passphrase = '')
    {
        $key = openssl_pkey_new(array(
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ));

        $csr = openssl_csr_new(array('commonName' => 'missivus-test'), $key, array('digest_alg' => 'sha256'));
        $certificate = openssl_csr_sign($csr, null, $key, 365, array('digest_alg' => 'sha256'));

        $privatePem = '';
        openssl_pkey_export($key, $privatePem, $passphrase === '' ? null : $passphrase);

        $certificatePem = '';
        openssl_x509_export($certificate, $certificatePem);

        $this->path = tempnam(sys_get_temp_dir(), 'missivus-cert-') . '.pem';
        file_put_contents($this->path, $privatePem . $certificatePem);
        chmod($this->path, 0600);

        $details = openssl_pkey_get_details($key);
        $this->publicKeyPath = tempnam(sys_get_temp_dir(), 'missivus-pub-') . '.pem';
        file_put_contents($this->publicKeyPath, $details['key']);
    }

    public function __destruct()
    {
        foreach (array($this->path, $this->publicKeyPath) as $file) {
            if ($file !== null && is_file($file)) {
                @unlink($file);
            }
        }
    }
}
