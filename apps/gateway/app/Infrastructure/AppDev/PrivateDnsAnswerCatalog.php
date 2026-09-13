<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DnsQuestion;
use App\Domain\AppDev\DnsRequester;

final readonly class PrivateDnsAnswerCatalog
{
    /**
     * @param  array<string, string>  $exact
     * @param  array<string, string>  $suffixes
     * @param  array<string, array<string, string>>  $overrides
     */
    public function __construct(
        public array $exact,
        public array $suffixes,
        public array $overrides = [],
    ) {}

    public static function fromDnsmasqConfiguration(string $configuration): self
    {
        $exact = [];
        $suffixes = [];

        foreach (preg_split('/\R/', $configuration) ?: [] as $line) {
            if (preg_match('/^host-record=([^,]+),([^,#\s]+)$/', $line, $matches) === 1) {
                $exact[self::normalizeName($matches[1])] = $matches[2];

                continue;
            }

            if (preg_match('/^address=\/\.([^\/]+)\/(.+)$/', $line, $matches) === 1) {
                $suffixes[self::normalizeName($matches[1])] = $matches[2];
            }
        }

        return new self($exact, $suffixes);
    }

    /**
     * @param  array{records?: array<string, string>, suffixes?: array<string, string>, overrides?: array<string, array<string, string>>}  $published
     */
    public static function fromPublished(array $published): self
    {
        return new self(
            exact: $published['records'] ?? [],
            suffixes: $published['suffixes'] ?? [],
            overrides: $published['overrides'] ?? [],
        );
    }

    /**
     * @param  array<string, string>  $overrides
     */
    public function withRequesterOverrides(string $cacheKey, array $overrides): self
    {
        $all = $this->overrides;
        $all[$cacheKey] = $overrides;

        return new self($this->exact, $this->suffixes, $all);
    }

    public function addressFor(DnsQuestion $question, DnsRequester $requester): ?string
    {
        $name = $question->normalizedName();
        $override = $this->overrides[$requester->cacheKey()][$name] ?? null;

        if (is_string($override) && $override !== '') {
            return $override;
        }

        if (isset($this->exact[$name])) {
            return $this->exact[$name];
        }

        foreach ($this->suffixes as $suffix => $address) {
            if ($name === $suffix || str_ends_with($name, '.'.$suffix)) {
                return $address;
            }
        }

        return null;
    }

    /**
     * @return array{records: array<string, string>, suffixes: array<string, string>, overrides: array<string, array<string, string>>}
     */
    public function toPublished(): array
    {
        return [
            'records' => $this->exact,
            'suffixes' => $this->suffixes,
            'overrides' => $this->overrides,
        ];
    }

    private static function normalizeName(string $name): string
    {
        return rtrim(strtolower($name), '.');
    }
}
