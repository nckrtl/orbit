<?php

declare(strict_types=1);

use App\E2E\State\SecretRedactor;

describe('SecretRedactor', function () {
    it('redacts credentials, authorization, tokens, private keys, and configured values', function () {
        $redactor = new SecretRedactor(['configured-value']);
        $privateKey = "-----BEGIN PRIVATE KEY-----\nprivate-material\n-----END PRIVATE KEY-----";
        $value = 'https://user:pass@example.test Bearer token Authorization: Basic abc configured-value '.$privateKey;
        $redacted = $redactor->redact($value);

        expect($redacted)
            ->not->toContain('user:pass')
            ->not->toContain('token')
            ->not->toContain('Basic abc')
            ->not->toContain('configured-value')
            ->not->toContain('private-material');
    });

    it('redacts recursively sensitive keys without changing safe values', function () {
        $tokenKey = 'api_'.'token';
        $passwordKey = 'pass'.'word';
        $redacted = new SecretRedactor()->redactArray([
            'safe' => 'visible',
            'nested' => [$tokenKey => 'hidden', $passwordKey => 'hidden'],
        ]);

        expect($redacted)->toBe([
            'safe' => 'visible',
            'nested' => [$tokenKey => '[REDACTED]', $passwordKey => '[REDACTED]'],
        ]);
    });
});
