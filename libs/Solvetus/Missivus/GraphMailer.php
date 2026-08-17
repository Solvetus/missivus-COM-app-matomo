<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Solvetus\Missivus;

use Solvetus\Missivus\Auth\TokenProvider;
use Solvetus\Missivus\Contract\HttpClientInterface;
use Solvetus\Missivus\Contract\HttpResponse;
use Solvetus\Missivus\Contract\LoggerInterface;
use Solvetus\Missivus\Contract\NullLogger;
use Solvetus\Missivus\Exception\GraphException;

/**
 * The vendorable transport. Nothing in this file knows what Matomo or WordPress is.
 *
 * Two send paths, chosen automatically per message:
 *
 *  - Direct: POST /users/{sender}/sendMail with attachments inline as base64. Graph accepts inline
 *    fileAttachments below 3 MB, and bounds the whole request at 4 MB.
 *  - Draft: for anything larger, create a draft, push each big file through an upload session in
 *    chunks, then send the draft. Scheduled-report PDFs must never fail on size, so this choice is
 *    automatic and total-aware — there is no setting that can route a large PDF down the inline
 *    path.
 */
class GraphMailer
{
    /** A single fileAttachment must be under 3 MB; above that Graph requires an upload session. */
    const LARGE_ATTACHMENT_THRESHOLD = 3145728;

    /** The whole sendMail request is bounded at 4 MB, so the total matters too. */
    const TOTAL_INLINE_BUDGET = 3145728;

    /** Under 4 MB as Microsoft advises, and an exact multiple of 320 KiB. */
    const UPLOAD_CHUNK_BYTES = 3276800;

    const API_VERSION = 'v1.0';

    /** @var TokenProvider */
    private $tokens;

    /** @var HttpClientInterface */
    private $http;

    /** @var LoggerInterface */
    private $logger;

    /** @var Redactor */
    private $redactor;

    /** @var string */
    private $senderMailbox;

    /** @var string */
    private $graphBaseUrl;

    /** @var bool */
    private $saveToSentItems;

    /**
     * @param TokenProvider        $tokens
     * @param HttpClientInterface  $http
     * @param Redactor             $redactor
     * @param string               $senderMailbox
     * @param bool                 $saveToSentItems
     * @param string               $graphBaseUrl
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        TokenProvider $tokens,
        HttpClientInterface $http,
        Redactor $redactor,
        $senderMailbox,
        $saveToSentItems = false,
        $graphBaseUrl = 'https://graph.microsoft.com',
        ?LoggerInterface $logger = null
    ) {
        $this->tokens = $tokens;
        $this->http = $http;
        $this->redactor = $redactor;
        $this->senderMailbox = trim((string) $senderMailbox);
        $this->saveToSentItems = (bool) $saveToSentItems;
        // A bearer token goes to this host. It is refused unless it is a bare https origin.
        $this->graphBaseUrl = Endpoint::normalise($graphBaseUrl, 'graph_base_url');
        $this->logger = $logger === null ? new NullLogger() : $logger;
    }

    /**
     * @param Message $message
     * @return void
     * @throws GraphException On any failure. Never returns false, never fails quietly.
     */
    public function send(Message $message)
    {
        if ($this->senderMailbox === '') {
            throw new GraphException('Missivus is not configured: missing sender mailbox');
        }

        if (!$message->hasRecipients()) {
            throw new GraphException('Missivus: refusing to send a message with no recipients');
        }

        if ($this->needsDraftPath($message)) {
            $this->sendViaDraft($message);
            return;
        }

        $this->sendDirect($message);
    }

    /**
     * @param Message $message
     * @return bool
     */
    private function needsDraftPath(Message $message)
    {
        $total = 0;

        foreach ($message->getAttachments() as $attachment) {
            if ($attachment->getSize() >= self::LARGE_ATTACHMENT_THRESHOLD) {
                return true;
            }
            $total += $attachment->getSize();
        }

        return $total >= self::TOTAL_INLINE_BUDGET;
    }

    /**
     * POST /users/{sender}/sendMail — everything inline.
     *
     * @param Message $message
     * @return void
     * @throws GraphException
     */
    private function sendDirect(Message $message)
    {
        $graphMessage = $message->toGraphMessage();
        $attachments = array();

        foreach ($message->getAttachments() as $attachment) {
            $attachments[] = $attachment->toGraphFileAttachment();
        }

        if (!empty($attachments)) {
            $graphMessage['attachments'] = $attachments;
        }

        $payload = array(
            'message' => $graphMessage,
            'saveToSentItems' => $this->saveToSentItems,
        );

        $response = $this->authorisedPost($this->userUrl('/sendMail'), $payload);

        // sendMail acknowledges with 202; anything else is a failure worth surfacing.
        if ($response->getStatus() !== 202) {
            throw $this->failure('Missivus: Graph rejected the message', $response);
        }
    }

    /**
     * Draft → upload session per large file → send.
     *
     * @param Message $message
     * @return void
     * @throws GraphException
     */
    private function sendViaDraft(Message $message)
    {
        $small = array();
        $large = array();

        foreach ($message->getAttachments() as $attachment) {
            if ($attachment->getSize() >= self::LARGE_ATTACHMENT_THRESHOLD) {
                $large[] = $attachment;
            } else {
                $small[] = $attachment;
            }
        }

        // Small and inline attachments ride along in the draft, which keeps CID images working.
        $graphMessage = $message->toGraphMessage();
        if (!empty($small)) {
            $inline = array();
            foreach ($small as $attachment) {
                $inline[] = $attachment->toGraphFileAttachment();
            }
            $graphMessage['attachments'] = $inline;
        }

        $response = $this->authorisedPost($this->userUrl('/messages'), $graphMessage);

        if ($response->getStatus() !== 201) {
            throw $this->failure(
                'Missivus: Graph refused to create the draft message. This step needs the'
                . ' Mail.ReadWrite application permission in addition to Mail.Send',
                $response
            );
        }

        $draft = $response->getJson();

        if (empty($draft['id'])) {
            throw $this->failure('Missivus: the created draft had no id', $response);
        }

        $draftId = (string) $draft['id'];

        try {
            foreach ($large as $attachment) {
                $this->uploadLargeAttachment($draftId, $attachment);
            }

            $sendResponse = $this->authorisedPost(
                $this->userUrl('/messages/' . rawurlencode($draftId) . '/send'),
                null
            );

            if ($sendResponse->getStatus() !== 202) {
                throw $this->failure('Missivus: Graph rejected the draft send', $sendResponse);
            }
        } catch (GraphException $e) {
            // The HTTP seam is deliberately two methods (post/put), so there is no DELETE to tidy
            // the draft with. Name it instead: an operator can remove it, and a silent orphan in
            // the shared mailbox would be worse than a noisy log line.
            $this->logger->error(
                'Missivus: sending failed after the draft was created. An unsent draft may remain'
                . ' in ' . $this->senderMailbox . ' with id ' . $draftId
            );

            throw $e;
        }
    }

    /**
     * @param string     $draftId
     * @param Attachment $attachment
     * @return void
     * @throws GraphException
     */
    private function uploadLargeAttachment($draftId, Attachment $attachment)
    {
        $total = $attachment->getSize();

        $sessionResponse = $this->authorisedPost(
            $this->userUrl('/messages/' . rawurlencode($draftId) . '/attachments/createUploadSession'),
            array(
                'AttachmentItem' => array(
                    'attachmentType' => 'file',
                    'name' => $attachment->getName(),
                    'size' => $total,
                    'contentType' => $attachment->getMimeType(),
                    'isInline' => $attachment->isInline(),
                ),
            )
        );

        if ($sessionResponse->getStatus() !== 201) {
            throw $this->failure(
                'Missivus: Graph refused an upload session for "' . $attachment->getName() . '"',
                $sessionResponse
            );
        }

        $session = $sessionResponse->getJson();

        if (empty($session['uploadUrl'])) {
            throw $this->failure('Missivus: the upload session had no uploadUrl', $sessionResponse);
        }

        $uploadUrl = (string) $session['uploadUrl'];

        for ($offset = 0; $offset < $total; $offset += self::UPLOAD_CHUNK_BYTES) {
            $chunk = substr($attachment->getBytes(), $offset, self::UPLOAD_CHUNK_BYTES);
            $length = strlen($chunk);
            $end = $offset + $length - 1;
            $isFinalChunk = ($end + 1) >= $total;

            // No Authorization header: uploadUrl is pre-authenticated for outlook.office.com, and
            // attaching our Graph bearer token to a different host would leak it.
            $chunkResponse = $this->put(
                $uploadUrl,
                $chunk,
                array(
                    'Content-Type' => 'application/octet-stream',
                    'Content-Length' => (string) $length,
                    'Content-Range' => 'bytes ' . $offset . '-' . $end . '/' . $total,
                )
            );

            // Intermediate chunks answer 200; the last one answers 201 with a Location header.
            $expected = $isFinalChunk ? 201 : 200;

            if ($chunkResponse->getStatus() !== $expected) {
                throw $this->failure(
                    'Missivus: uploading "' . $attachment->getName() . '" failed at bytes '
                    . $offset . '-' . $end . ' of ' . $total,
                    $chunkResponse
                );
            }
        }
    }

    /**
     * POST to Graph with a bearer token, retrying exactly once on a 401 with a fresh token.
     *
     * @param string     $url
     * @param array|null $payload JSON-encoded when present; null sends an empty body.
     * @return HttpResponse
     * @throws GraphException
     */
    private function authorisedPost($url, $payload)
    {
        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new GraphException('Missivus: the message could not be encoded as JSON');
        }

        $response = $this->post($url, $body, $this->tokens->getToken());

        if ($response->getStatus() === 401) {
            // A token can be revoked mid-life. One retry with a fresh one, then give up — a loop
            // here would hammer Entra on a genuinely broken app registration.
            $this->logger->warning('Missivus: Graph returned 401; refreshing the token and retrying once');
            $this->tokens->invalidate();
            $response = $this->post($url, $body, $this->tokens->getToken());
        }

        return $response;
    }

    /**
     * @param string $url
     * @param string $body
     * @param string $token
     * @return HttpResponse
     * @throws GraphException
     */
    private function post($url, $body, $token)
    {
        try {
            return $this->http->post(
                $url,
                $body,
                array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                )
            );
        } catch (\RuntimeException $e) {
            throw new GraphException(
                'Missivus: could not reach the Microsoft Graph API: '
                . $this->redactor->redact($e->getMessage())
            );
        }
    }

    /**
     * PUT a chunk to a pre-authenticated upload URL.
     *
     * Two things make this worth its own method rather than a bare $this->http->put(). A transport
     * failure here must surface as a GraphException like every other failure, or it would sail past
     * the transport's catch and skip the fallback entirely. And the upload URL is itself a
     * credential — anyone holding it can write to that draft — so it is masked out of the error
     * message rather than left for a log file to keep.
     *
     * @param string $url
     * @param string $chunk
     * @param array  $headers
     * @return HttpResponse
     * @throws GraphException
     */
    private function put($url, $chunk, array $headers)
    {
        try {
            return $this->http->put($url, $chunk, $headers, 120);
        } catch (\RuntimeException $e) {
            throw new GraphException(
                'Missivus: could not reach the attachment upload endpoint: '
                . $this->redactor->redact(str_replace($url, Redactor::MASK, $e->getMessage()))
            );
        }
    }

    /**
     * @param string       $summary
     * @param HttpResponse $response
     * @return GraphException
     */
    private function failure($summary, HttpResponse $response)
    {
        return new GraphException(
            $summary . ' (HTTP ' . $response->getStatus() . ')',
            $response->getStatus(),
            $this->redactor->redactBody($response->getBody())
        );
    }

    /**
     * @param string $suffix
     * @return string
     */
    private function userUrl($suffix)
    {
        return $this->graphBaseUrl . '/' . self::API_VERSION . '/users/'
            . rawurlencode($this->senderMailbox) . $suffix;
    }
}
