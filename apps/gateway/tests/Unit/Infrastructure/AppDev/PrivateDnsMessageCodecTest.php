<?php

declare(strict_types=1);

use App\Domain\AppDev\DnsRecordType;
use App\Domain\AppDev\PrivateDnsAnswer;
use App\Infrastructure\AppDev\PrivateDnsMessageCodec;

it('decodes a question and ignores an EDNS client subnet identity', function (): void {
    $codec = new PrivateDnsMessageCodec;
    $message = $codec->encodeQuery('app.cluster.test', DnsRecordType::A, id: 42, ednsClientSubnet: '10.44.0.9');

    $query = $codec->decodeQuestion($message);

    expect($query->id)
        ->toBe(42)
        ->and($query->question->normalizedName())
        ->toBe('app.cluster.test')
        ->and($query->question->type)
        ->toBe(DnsRecordType::A)
        ->and($query->contentIdentity)
        ->toBe('10.44.0.9');
});

it('encodes an authoritative A answer for the asked name', function (): void {
    $codec = new PrivateDnsMessageCodec;
    $query = $codec->decodeQuestion($codec->encodeQuery('gateway.orbit'));

    $response = $codec->encodeAnswer($query, PrivateDnsAnswer::a('10.44.0.1'));
    $decoded = $codec->decodeQuestion($response);
    $address = unpack('Nip', substr($response, -4));

    expect($decoded->question->normalizedName())
        ->toBe('gateway.orbit')
        ->and($address['ip'] ?? null)
        ->toBe(ip2long('10.44.0.1'));
});
