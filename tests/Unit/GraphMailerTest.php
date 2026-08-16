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
use Piwik\Plugins\Missivus\tests\Framework\RecordingLogger;
use Solvetus\Missivus\Attachment;
use Solvetus\Missivus\Auth\Credentials;
use Solvetus\Missivus\Auth\TokenProvider;
use Solvetus\Missivus\Exception\GraphException;
use Solvetus\Missivus\GraphMailer;
use Solvetus\Missivus\Message;
use Solvetus\Missivus\Redactor;

require_once __DIR__ . '/../Framework/Doubles.php';

/**
 * Everything here asserts on the exact request Missivus would have put on the wire — the URL, the
 * headers, and the JSON body — against a scripted fake Graph endpoint.
 *
 * @group Missivus
 */
class GraphMailerTest extends TestCase
{
    const SENDER = 'noreply@example.com';
    const GRAPH = 'https://graph.example.test';

    /** @var FakeHttpClient */
    private $http;

    /** @var ArrayTokenCache */
    private $cache;

    /** @var RecordingLogger */
    private $logger;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->cache = new ArrayTokenCache();
        $this->logger = new RecordingLogger();
    }

    public function testAPlainMessageIsPostedToSendMail()
    {
        $this->queueToken();
        $this->http->queueRaw(202);

        $this->mailer()->send($this->message());

        $this->assertSame(2, $this->http->count(), 'One token request, one send');

        $send = $this->http->request(1);
        $this->assertSame('POST', $send['method']);
        $this->assertSame(self::GRAPH . '/v1.0/users/noreply%40example.com/sendMail', $send['url']);
        $this->assertSame('Bearer token-abc', $send['headers']['Authorization']);
        $this->assertSame('application/json', $send['headers']['Content-Type']);

        $body = $this->http->requestJson(1);
        $this->assertSame('Hello', $body['message']['subject']);
        $this->assertSame('HTML', $body['message']['body']['contentType']);
        $this->assertSame('<p>Hi</p>', $body['message']['body']['content']);
        $this->assertFalse($body['saveToSentItems']);
        $this->assertSame(self::SENDER, $body['message']['from']['emailAddress']['address']);
    }

    public function testAMessageWithNoHtmlIsSentAsPlainText()
    {
        $this->queueToken();
        $this->http->queueRaw(202);

        $message = (new Message())
            ->setFrom(self::SENDER)
            ->addTo('someone@example.org')
            ->setSubject('Text only')
            ->setTextBody('Just words.');

        $this->mailer()->send($message);

        $body = $this->http->requestJson(1);
        $this->assertSame('Text', $body['message']['body']['contentType']);
        $this->assertSame('Just words.', $body['message']['body']['content']);
    }

    public function testEveryRecipientKindIsMapped()
    {
        $this->queueToken();
        $this->http->queueRaw(202);

        $message = (new Message())
            ->setFrom(self::SENDER)
            ->addTo('first@example.org', 'First Person')
            ->addTo('second@example.org')
            ->addCc('copied@example.org')
            ->addBcc('hidden@example.org')
            ->addReplyTo('replies@example.org')
            ->setSubject('Many')
            ->setTextBody('body');

        $this->mailer()->send($message);

        $graphMessage = $this->http->requestJson(1)['message'];

        $this->assertCount(2, $graphMessage['toRecipients']);
        $this->assertSame('first@example.org', $graphMessage['toRecipients'][0]['emailAddress']['address']);
        $this->assertSame('First Person', $graphMessage['toRecipients'][0]['emailAddress']['name']);
        $this->assertArrayNotHasKey('name', $graphMessage['toRecipients'][1]['emailAddress']);
        $this->assertSame('copied@example.org', $graphMessage['ccRecipients'][0]['emailAddress']['address']);
        $this->assertSame('hidden@example.org', $graphMessage['bccRecipients'][0]['emailAddress']['address']);
        $this->assertSame('replies@example.org', $graphMessage['replyTo'][0]['emailAddress']['address']);
    }

    public function testSaveToSentItemsIsHonoured()
    {
        $this->queueToken();
        $this->http->queueRaw(202);

        $this->mailer(true)->send($this->message());

        $this->assertTrue($this->http->requestJson(1)['saveToSentItems']);
    }

    public function testASmallAttachmentGoesInlineInASingleRequest()
    {
        $this->queueToken();
        $this->http->queueRaw(202);

        $message = $this->message();
        $message->addAttachment(new Attachment('report.pdf', 'PDF-BYTES', 'application/pdf'));

        $this->mailer()->send($message);

        $this->assertSame(2, $this->http->count(), 'Small attachments need no upload session');

        $attachment = $this->http->requestJson(1)['message']['attachments'][0];
        $this->assertSame('#microsoft.graph.fileAttachment', $attachment['@odata.type']);
        $this->assertSame('report.pdf', $attachment['name']);
        $this->assertSame('application/pdf', $attachment['contentType']);
        $this->assertSame('PDF-BYTES', base64_decode($attachment['contentBytes']));
        $this->assertArrayNotHasKey('isInline', $attachment);
    }

    public function testAnInlineAttachmentCarriesItsContentId()
    {
        $this->queueToken();
        $this->http->queueRaw(202);

        $message = $this->message();
        $message->addAttachment(new Attachment('logo.png', 'PNG-BYTES', 'image/png', 'logo-cid'));

        $this->mailer()->send($message);

        $attachment = $this->http->requestJson(1)['message']['attachments'][0];
        $this->assertTrue($attachment['isInline']);
        $this->assertSame('logo-cid', $attachment['contentId']);
    }

    public function testALargeAttachmentGoesThroughDraftUploadSessionAndSend()
    {
        $bytes = str_repeat('A', 4 * 1024 * 1024); // 4 MB — over the 3 MB inline ceiling.

        $this->queueToken();
        $this->http->queueJson(201, array('id' => 'DRAFT-1'));
        $this->http->queueJson(201, array('uploadUrl' => 'https://outlook.example.test/upload/session-1'));
        $this->http->queueJson(200, array('nextExpectedRanges' => array('3276800')));
        $this->http->queueRaw(201);
        $this->http->queueRaw(202);

        $message = $this->message();
        $message->addAttachment(new Attachment('big.pdf', $bytes, 'application/pdf'));

        $this->mailer()->send($message);

        $this->assertSame(
            array(
                'https://login.example.test/tenant-id/oauth2/v2.0/token',
                self::GRAPH . '/v1.0/users/noreply%40example.com/messages',
                self::GRAPH . '/v1.0/users/noreply%40example.com/messages/DRAFT-1/attachments/createUploadSession',
                'https://outlook.example.test/upload/session-1',
                'https://outlook.example.test/upload/session-1',
                self::GRAPH . '/v1.0/users/noreply%40example.com/messages/DRAFT-1/send',
            ),
            $this->http->urls()
        );

        // The draft itself must not carry the large file inline.
        $this->assertArrayNotHasKey('attachments', $this->http->requestJson(1));

        $session = $this->http->requestJson(2)['AttachmentItem'];
        $this->assertSame('file', $session['attachmentType']);
        $this->assertSame('big.pdf', $session['name']);
        $this->assertSame(4 * 1024 * 1024, $session['size']);

        $first = $this->http->request(3);
        $this->assertSame('PUT', $first['method']);
        $this->assertSame('bytes 0-3276799/4194304', $first['headers']['Content-Range']);
        $this->assertSame('application/octet-stream', $first['headers']['Content-Type']);
        $this->assertSame('3276800', $first['headers']['Content-Length']);
        $this->assertArrayNotHasKey(
            'Authorization',
            $first['headers'],
            'The upload URL is pre-authenticated; sending our Graph token to another host would leak it'
        );

        $second = $this->http->request(4);
        $this->assertSame('bytes 3276800-4194303/4194304', $second['headers']['Content-Range']);
        $this->assertSame(4194304 - 3276800, strlen($second['body']));

        $this->assertSame('', $this->http->request(5)['body'], 'The send call takes no body');
    }

    public function testManySmallAttachmentsThatExceedTheBudgetTogetherAlsoUseTheDraftPath()
    {
        $this->queueToken();
        $this->http->queueJson(201, array('id' => 'DRAFT-2'));
        $this->http->queueRaw(202);

        $message = $this->message();
        // Each is under the per-file ceiling; together they blow the request budget.
        for ($i = 0; $i < 3; $i++) {
            $message->addAttachment(new Attachment('part' . $i . '.pdf', str_repeat('B', 1200000), 'application/pdf'));
        }

        $this->mailer()->send($message);

        $this->assertStringContainsString('/messages', $this->http->request(1)['url']);
        $this->assertCount(
            3,
            $this->http->requestJson(1)['attachments'],
            'They are still small enough to ride along in the draft'
        );
        $this->assertStringContainsString('/send', $this->http->request(2)['url']);
    }

    public function testAFailedUploadNamesTheOrphanedDraftAndRethrows()
    {
        $this->queueToken();
        $this->http->queueJson(201, array('id' => 'DRAFT-3'));
        $this->http->queueRaw(403, '{"error":{"code":"ErrorAccessDenied"}}');

        $message = $this->message();
        $message->addAttachment(new Attachment('big.pdf', str_repeat('A', 4 * 1024 * 1024), 'application/pdf'));

        try {
            $this->mailer()->send($message);
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertSame(403, $e->getHttpStatus());
            $this->assertStringContainsString('DRAFT-3', $this->logger->everything());
        }
    }

    public function testADraftRefusalPointsAtTheMissingMailReadWritePermission()
    {
        $this->queueToken();
        $this->http->queueRaw(403, '{"error":{"code":"ErrorAccessDenied"}}');

        $message = $this->message();
        $message->addAttachment(new Attachment('big.pdf', str_repeat('A', 4 * 1024 * 1024), 'application/pdf'));

        try {
            $this->mailer()->send($message);
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertStringContainsString('Mail.ReadWrite', $e->getMessage());
        }
    }

    public function testA401RefreshesTheTokenAndRetriesExactlyOnce()
    {
        $this->queueToken('stale-token');
        $this->http->queueRaw(401, '{"error":{"code":"InvalidAuthenticationToken"}}');
        $this->queueToken('fresh-token');
        $this->http->queueRaw(202);

        $this->mailer()->send($this->message());

        $this->assertSame(4, $this->http->count());
        $this->assertSame('Bearer stale-token', $this->http->request(1)['headers']['Authorization']);
        $this->assertSame('Bearer fresh-token', $this->http->request(3)['headers']['Authorization']);
        $this->assertStringContainsString('401', $this->logger->everything());
    }

    public function testASecondConsecutive401Fails()
    {
        $this->queueToken('one');
        $this->http->queueRaw(401, '{"error":{"code":"InvalidAuthenticationToken"}}');
        $this->queueToken('two');
        $this->http->queueRaw(401, '{"error":{"code":"InvalidAuthenticationToken"}}');

        $this->expectException(GraphException::class);

        $this->mailer()->send($this->message());
    }

    public function testANonAcceptedStatusIsAFailureCarryingTheGraphErrorBody()
    {
        $this->queueToken();
        $this->http->queueRaw(400, '{"error":{"code":"ErrorInvalidRecipients","message":"bad address"}}');

        try {
            $this->mailer()->send($this->message());
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertSame(400, $e->getHttpStatus());
            $this->assertStringContainsString('ErrorInvalidRecipients', $e->getMessage());
        }
    }

    public function testAMessageWithNoRecipientsIsRefusedBeforeAnyRequest()
    {
        $message = (new Message())->setFrom(self::SENDER)->setSubject('Nobody')->setTextBody('x');

        try {
            $this->mailer()->send($message);
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertStringContainsString('no recipients', $e->getMessage());
            $this->assertSame(0, $this->http->count());
        }
    }

    public function testAnEmptySenderMailboxIsRefused()
    {
        $this->expectException(GraphException::class);
        $this->expectExceptionMessage('missing sender mailbox');

        $this->mailer(false, '')->send($this->message());
    }

    /**
     * @param string $token
     * @return void
     */
    private function queueToken($token = 'token-abc')
    {
        $this->http->queueJson(200, array('access_token' => $token, 'expires_in' => 3600));
    }

    /**
     * @return Message
     */
    private function message()
    {
        return (new Message())
            ->setFrom(self::SENDER)
            ->addTo('someone@example.org')
            ->setSubject('Hello')
            ->setHtmlBody('<p>Hi</p>')
            ->setTextBody('Hi');
    }

    /**
     * @param bool        $saveToSentItems
     * @param string|null $sender
     * @return GraphMailer
     */
    private function mailer($saveToSentItems = false, $sender = null)
    {
        $credentials = (new Credentials('tenant-id', 'client-id', Credentials::METHOD_SECRET))
            ->withClientSecret('super-secret-value');

        $redactor = new Redactor($credentials->getSecretLiterals());

        $tokens = new TokenProvider(
            $credentials,
            $this->http,
            $this->cache,
            $redactor,
            'https://login.example.test'
        );

        return new GraphMailer(
            $tokens,
            $this->http,
            $redactor,
            $sender === null ? self::SENDER : $sender,
            $saveToSentItems,
            self::GRAPH,
            $this->logger
        );
    }
}
