<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DnsQuestion;
use App\Domain\AppDev\DnsRecordType;
use App\Domain\AppDev\DnsRequester;
use App\Domain\AppDev\PrivateDnsAnswer;
use App\Domain\AppDev\PrivateDnsAnswerSelector;

final readonly class CatalogPrivateDnsAnswerSelector implements PrivateDnsAnswerSelector
{
    public function __construct(
        private PrivateDnsAnswerCatalog $catalog,
    ) {}

    public function select(DnsQuestion $question, DnsRequester $requester): PrivateDnsAnswer
    {
        if ($question->type !== DnsRecordType::A) {
            return PrivateDnsAnswer::empty();
        }

        $address = $this->catalog->addressFor($question, $requester);
        if ($address === null) {
            return PrivateDnsAnswer::empty();
        }

        return PrivateDnsAnswer::a($address);
    }
}
