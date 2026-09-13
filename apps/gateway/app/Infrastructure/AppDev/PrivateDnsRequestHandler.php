<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\PrivateDnsAnswerCache;
use App\Domain\AppDev\PrivateDnsAnswerSelector;
use App\Domain\AppDev\PrivateDnsRequesterResolver;

final readonly class PrivateDnsRequestHandler
{
    public function __construct(
        private PrivateDnsRequesterResolver $requesters,
        private PrivateDnsAnswerSelector $selector,
        private PrivateDnsAnswerCache $cache,
        private PrivateDnsMessageCodec $codec = new PrivateDnsMessageCodec,
    ) {}

    public function handle(string $sourceAddress, string $message): string
    {
        $query = $this->codec->decodeQuestion($message);
        $requester = $this->requesters->resolve($sourceAddress);
        $answer = $this->cache->remember(
            $requester,
            $query->question,
            fn () => $this->selector->select($query->question, $requester),
        );

        return $this->codec->encodeAnswer($query, $answer);
    }
}
