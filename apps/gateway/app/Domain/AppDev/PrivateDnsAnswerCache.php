<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

interface PrivateDnsAnswerCache
{
    /**
     * @param  callable(): PrivateDnsAnswer  $select
     */
    public function remember(DnsRequester $requester, DnsQuestion $question, callable $select): PrivateDnsAnswer;

    public function flush(): void;
}
