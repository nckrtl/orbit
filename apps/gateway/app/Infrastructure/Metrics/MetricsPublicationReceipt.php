<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use InvalidArgumentException;
use LogicException;

final readonly class MetricsPublicationReceipt
{
    private const string Marker = 'orbit-metrics-publication:';

    private function __construct(
        private string $change,
        private ?string $previousPublication,
    ) {}

    public static function unchanged(): self
    {
        return new self('unchanged', null);
    }

    public static function created(): self
    {
        return new self('created', null);
    }

    public static function replaced(string $previousPublication): self
    {
        if ($previousPublication === '') {
            throw new InvalidArgumentException('A replaced Metrics publication requires its previous value.');
        }

        return new self('replaced', $previousPublication);
    }

    public static function fromProcessOutput(string $output): self
    {
        $lines = array_reverse(preg_split('/\R/', trim($output)) ?: []);

        foreach ($lines as $line) {
            if ($line === self::Marker.'unchanged') {
                return self::unchanged();
            }

            if ($line === self::Marker.'created') {
                return self::created();
            }

            $encoded = str_starts_with($line, self::Marker.'replaced:')
                ? substr($line, strlen(self::Marker.'replaced:'))
                : false;
            $previous = is_string($encoded) ? base64_decode($encoded, strict: true) : false;

            if (is_string($previous) && $previous !== '') {
                return self::replaced($previous);
            }
        }

        throw new InvalidArgumentException('Metrics publication output did not contain a valid change receipt.');
    }

    public function isUnchanged(): bool
    {
        return $this->change === 'unchanged';
    }

    public function wasCreated(): bool
    {
        return $this->change === 'created';
    }

    public function previousPublication(): string
    {
        if ($this->previousPublication === null) {
            throw new LogicException('Only a replaced Metrics publication has a previous value.');
        }

        return $this->previousPublication;
    }
}
