<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DnsQuestion;
use App\Domain\AppDev\DnsRequester;
use App\Domain\AppDev\PrivateDnsAnswer;
use App\Domain\AppDev\PrivateDnsAnswerCache;

final class InMemoryPrivateDnsAnswerCache implements PrivateDnsAnswerCache
{
    /** @var array<string, PrivateDnsAnswer> */
    private array $entries = [];

    public function remember(DnsRequester $requester, DnsQuestion $question, callable $select): PrivateDnsAnswer
    {
        $key = $requester->cacheKey().'|'.$question->normalizedName().'|'.$question->type->value;

        return $this->entries[$key] ??= $select();
    }

    public function flush(): void
    {
        $this->entries = [];
    }
}
