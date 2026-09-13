<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DnsQuestion;
use App\Domain\AppDev\DnsRequester;
use App\Domain\AppDev\PrivateDnsAnswer;
use App\Domain\AppDev\PrivateDnsAnswerSelector;

final readonly class StoredPrivateDnsAnswerSelector implements PrivateDnsAnswerSelector
{
    public function __construct(
        private FilePrivateDnsCatalogStore $store,
    ) {}

    public function select(DnsQuestion $question, DnsRequester $requester): PrivateDnsAnswer
    {
        $this->store->refresh();

        return new CatalogPrivateDnsAnswerSelector($this->store->catalog())->select($question, $requester);
    }
}
