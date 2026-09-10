<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * One exact scoped selector for a preserved Incus pool or base image.
 */
final readonly class PreservedIncusReference
{
    private function __construct(
        public string $kind,
        public string $remote,
        public string $project,
        public string $selector,
        public string $displayIdentity,
    ) {}

    public static function supports(string $kind): bool
    {
        return in_array($kind, ['pools', 'base_images'], true);
    }

    /** @param array<string, mixed> $resource */
    public static function fromResource(string $kind, array $resource): self
    {
        if (! self::supports($kind)) {
            throw new InvalidArgumentException('The preserved Incus resource kind is invalid.');
        }

        $remote = $resource['remote'] ?? null;
        $project = $resource['project'] ?? null;
        $name = $resource['name'] ?? null;
        $identity = $resource['identity'] ?? null;
        $fingerprint = $resource['fingerprint'] ?? null;
        if (
            ! is_string($remote)
            || ! is_string($project)
            || ! is_string($name)
            || $name === ''
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/D', $remote) !== 1
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/D', $project) !== 1
        ) {
            throw new InvalidArgumentException('A preserved Incus reference has invalid scope or display identity.');
        }

        if ($kind === 'pools') {
            if (
                preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/D', $name) !== 1
                || ! is_string($identity)
                || $identity === ''
            ) {
                throw new InvalidArgumentException('A preserved pool reference is invalid.');
            }

            return new self($kind, $remote, $project, $name, $identity);
        }

        if (! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new InvalidArgumentException('A preserved base-image fingerprint is invalid.');
        }

        return new self($kind, $remote, $project, $fingerprint, $name);
    }

    public function key(): string
    {
        return $this->kind."\0".$this->remote."\0".$this->project."\0".$this->selector;
    }

    public function queryPath(): string
    {
        return $this->kind === 'pools'
            ? '/1.0/storage-pools/'.$this->selector
            : '/1.0/images/'.$this->selector;
    }

    /** @param array<array-key, mixed> $live */
    public function matchesLive(array $live): bool
    {
        $selector = $this->kind === 'pools' ? $live['name'] ?? null : $live['fingerprint'] ?? null;

        return $selector === $this->selector;
    }
}
