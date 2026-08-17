<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Solvetus\Missivus;

/**
 * One attached file, held in memory as raw bytes — which is how Piwik\Mail hands them over.
 */
class Attachment
{
    /** @var string */
    private $name;

    /** @var string */
    private $mimeType;

    /** @var string Raw bytes, not base64. */
    private $bytes;

    /** @var string Non-empty means an inline (CID-referenced) attachment. */
    private $contentId;

    /**
     * @param string $name
     * @param string $bytes
     * @param string $mimeType
     * @param string $contentId
     */
    public function __construct($name, $bytes, $mimeType = 'application/octet-stream', $contentId = '')
    {
        $this->name = (string) $name;
        $this->bytes = (string) $bytes;
        $this->mimeType = (string) $mimeType !== '' ? (string) $mimeType : 'application/octet-stream';
        $this->contentId = (string) $contentId;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getMimeType()
    {
        return $this->mimeType;
    }

    /**
     * @return string
     */
    public function getBytes()
    {
        return $this->bytes;
    }

    /**
     * @return string
     */
    public function getContentId()
    {
        return $this->contentId;
    }

    /**
     * @return bool
     */
    public function isInline()
    {
        return $this->contentId !== '';
    }

    /**
     * @return int
     */
    public function getSize()
    {
        return strlen($this->bytes);
    }

    /**
     * The Graph fileAttachment shape, for the inline path.
     *
     * @return array
     */
    public function toGraphFileAttachment()
    {
        $attachment = array(
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $this->name,
            'contentType' => $this->mimeType,
            'contentBytes' => base64_encode($this->bytes),
        );

        if ($this->isInline()) {
            $attachment['isInline'] = true;
            $attachment['contentId'] = $this->contentId;
        }

        return $attachment;
    }
}
