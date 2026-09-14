<?php

declare(strict_types=1);

namespace App\Infrastructure\Herdr;

use App\Domain\Herdr\HerdrObserveContract;
use App\Domain\Herdr\ObservationGrantClaims;
use App\Domain\Herdr\ObservationGrantSigner;
use App\Domain\Shared\ResourceOperationException;
use App\Models\JwksKey;
use JsonException;
use OpenSSLAsymmetricKey;

final readonly class OpenSslObservationGrantSigner implements ObservationGrantSigner
{
    public function sign(ObservationGrantClaims $claims): string
    {
        $key = $this->key();
        $header = $this->encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $key->kid]);
        $payload = $this->encode($claims->payload());
        $unsigned = $header.'.'.$payload;
        $signature = '';

        if (! openssl_sign($unsigned, $signature, $this->privateKey($key), OPENSSL_ALGO_SHA256)) {
            throw new ResourceOperationException(
                errorCode: 'herdr.grant_invalid',
                message: 'Orbit could not sign the observation grant.',
                status: 500,
            );
        }

        return $unsigned.'.'.$this->base64Url($signature);
    }

    public function verify(string $token): ObservationGrantClaims
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw $this->invalidGrant();
        }

        [$headerPart, $payloadPart, $signaturePart] = $parts;
        $header = $this->decodeObject($headerPart);
        $payload = $this->decodeObject($payloadPart);
        $signature = $this->base64UrlDecode($signaturePart);
        $key = $this->key();

        if (($header['alg'] ?? null) !== 'RS256' || ($header['kid'] ?? null) !== $key->kid) {
            throw $this->invalidGrant();
        }

        $verified = openssl_verify(
            $headerPart.'.'.$payloadPart,
            $signature,
            $this->publicKey($key),
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            throw $this->invalidGrant();
        }

        if (
            ($payload['iss'] ?? null) !== HerdrObserveContract::GrantIssuer
            || ($payload['aud'] ?? null) !== HerdrObserveContract::GrantAudience
            || ($payload['sub'] ?? null) !== HerdrObserveContract::GrantScope
        ) {
            throw $this->invalidGrant();
        }

        foreach (['node', 'session', 'pane', 'terminal', 'jti', 'origin'] as $field) {
            if (! is_string($payload[$field] ?? null) || $payload[$field] === '') {
                throw $this->invalidGrant();
            }
        }

        foreach (['cols', 'rows', 'iat', 'exp'] as $field) {
            if (! is_int($payload[$field] ?? null)) {
                throw $this->invalidGrant();
            }
        }

        return new ObservationGrantClaims(
            node: $payload['node'],
            session: $payload['session'],
            pane: $payload['pane'],
            terminal: $payload['terminal'],
            cols: $payload['cols'],
            rows: $payload['rows'],
            nonce: $payload['jti'],
            expiresAt: $payload['exp'],
            issuedAt: $payload['iat'],
            origin: $payload['origin'],
        );
    }

    /**
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array
    {
        $key = $this->key();
        $details = openssl_pkey_get_details($this->publicKey($key));

        if ($details === false || ! isset($details['rsa']) || ! is_array($details['rsa'])) {
            throw new ResourceOperationException(
                errorCode: 'herdr.grant_invalid',
                message: 'Orbit could not publish observation signing keys.',
                status: 500,
            );
        }

        $n = $details['rsa']['n'] ?? null;
        $e = $details['rsa']['e'] ?? null;

        if (! is_string($n) || ! is_string($e)) {
            throw new ResourceOperationException(
                errorCode: 'herdr.grant_invalid',
                message: 'Orbit could not publish observation signing keys.',
                status: 500,
            );
        }

        return [
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $key->kid,
                'n' => $this->base64Url($n),
                'e' => $this->base64Url($e),
            ]],
        ];
    }

    private function key(): JwksKey
    {
        $existing = JwksKey::query()->where('algorithm', 'RS256')->first();

        if ($existing instanceof JwksKey) {
            return $existing;
        }

        $generated = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (! $generated instanceof OpenSSLAsymmetricKey) {
            throw new ResourceOperationException(
                errorCode: 'herdr.grant_invalid',
                message: 'Orbit could not create an observation signing key.',
                status: 500,
            );
        }

        $privatePem = '';

        if (! openssl_pkey_export($generated, $privatePem)) {
            throw new ResourceOperationException(
                errorCode: 'herdr.grant_invalid',
                message: 'Orbit could not create an observation signing key.',
                status: 500,
            );
        }

        $details = openssl_pkey_get_details($generated);
        $publicPem = is_array($details) && is_string($details['key'] ?? null) ? $details['key'] : '';

        if ($publicPem === '') {
            throw new ResourceOperationException(
                errorCode: 'herdr.grant_invalid',
                message: 'Orbit could not create an observation signing key.',
                status: 500,
            );
        }

        return JwksKey::query()->create([
            'kid' => bin2hex(random_bytes(8)),
            'algorithm' => 'RS256',
            'private_pem' => $privatePem,
            'public_pem' => $publicPem,
        ]);
    }

    private function privateKey(JwksKey $key): OpenSSLAsymmetricKey
    {
        $parsed = openssl_pkey_get_private($key->private_pem);

        if (! $parsed instanceof OpenSSLAsymmetricKey) {
            throw new ResourceOperationException(
                errorCode: 'herdr.grant_invalid',
                message: 'Orbit could not load the observation signing key.',
                status: 500,
            );
        }

        return $parsed;
    }

    private function publicKey(JwksKey $key): OpenSSLAsymmetricKey
    {
        $parsed = openssl_pkey_get_public($key->public_pem);

        if (! $parsed instanceof OpenSSLAsymmetricKey) {
            throw new ResourceOperationException(
                errorCode: 'herdr.grant_invalid',
                message: 'Orbit could not load the observation signing key.',
                status: 500,
            );
        }

        return $parsed;
    }

    /**
     * @param  array<string, int|string>  $value
     */
    private function encode(array $value): string
    {
        return $this->base64Url(json_encode($value, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $value): array
    {
        try {
            $decoded = json_decode($this->base64UrlDecode($value), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalidGrant();
        }

        if (! is_array($decoded)) {
            throw $this->invalidGrant();
        }

        return $decoded;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        if (! is_string($decoded)) {
            throw $this->invalidGrant();
        }

        return $decoded;
    }

    private function invalidGrant(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'herdr.grant_invalid',
            message: 'The observation grant is invalid.',
            status: 403,
        );
    }
}
