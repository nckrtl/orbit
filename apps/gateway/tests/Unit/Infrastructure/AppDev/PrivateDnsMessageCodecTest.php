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

it('rejects a DNS name with an empty or overlong label', function (): void {
    $codec = new PrivateDnsMessageCodec;

    expect(fn (): string => $codec->encodeQuery('app..cluster.test'))
        ->toThrow(InvalidArgumentException::class, 'DNS label is not encodable.')
        ->and(fn (): string => $codec->encodeQuery(str_repeat('a', 64).'.test'))
        ->toThrow(InvalidArgumentException::class, 'DNS label is not encodable.');
});

it('encodes a DNS name whose longest label is 63 characters', function (): void {
    $codec = new PrivateDnsMessageCodec;
    $name = str_repeat('a', 63).'.test';

    $query = $codec->decodeQuestion($codec->encodeQuery($name));

    expect($query->question->normalizedName())->toBe($name);
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
