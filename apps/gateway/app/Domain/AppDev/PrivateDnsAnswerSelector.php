<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

interface PrivateDnsAnswerSelector
{
    public function select(DnsQuestion $question, DnsRequester $requester): PrivateDnsAnswer;
}
