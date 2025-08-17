<?php

namespace SlimStat\Dependencies\GuzzleHttp;

use SlimStat\Dependencies\GuzzleHttp\Psr7\Message;
use SlimStat\Dependencies\Psr\Http\Message\MessageInterface;

final class BodySummarizer implements BodySummarizerInterface
{
    /**
     * @var int|null
     */
    private $truncateAt;

    public function __construct(int $truncateAt = null)
    {
        $this->truncateAt = $truncateAt;
    }

    /**
     * Returns a summarized message body.
     */
    public function summarize(MessageInterface $message): ?string
    {
        return null === $this->truncateAt
            ? Message::bodySummary($message)
            : Message::bodySummary($message, $this->truncateAt);
    }
}
