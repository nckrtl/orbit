<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\PrivateDnsAnswerCache;
use App\Domain\AppDev\PrivateDnsAnswerSelector;
use App\Domain\AppDev\PrivateDnsRequesterResolver;
use App\Domain\AppDev\PrivateDnsUpstream;
use InvalidArgumentException;
use Throwable;

final readonly class PrivateDnsRequestHandler
{
    public function __construct(
        private PrivateDnsRequesterResolver $requesters,
        private PrivateDnsAnswerSelector $selector,
        private PrivateDnsAnswerCache $cache,
        private PrivateDnsMessageCodec $codec = new PrivateDnsMessageCodec,
        private ?PrivateDnsUpstream $upstream = null,
    ) {}

    public function handle(string $sourceAddress, string $message): string
    {
        try {
            $query = $this->codec->decodeQuestion($message);
        } catch (InvalidArgumentException $exception) {
            return $this->forward($message, $exception);
        }

        $requester = $this->requesters->resolve($sourceAddress);
        $answer = $this->cache->remember(
            $requester,
            $query->question,
            fn () => $this->selector->select($query->question, $requester),
        );

        if ($answer->authoritative || $this->upstream === null) {
            return $this->codec->encodeAnswer($query, $answer);
        }

        try {
            return $this->upstream->resolve($message);
        } catch (Throwable) {
            return $this->codec->encodeAnswer($query, $answer);
        }
    }

    private function forward(string $message, InvalidArgumentException $exception): string
    {
        if ($this->upstream === null) {
            throw $exception;
        }

        return $this->upstream->resolve($message);
    }
}
