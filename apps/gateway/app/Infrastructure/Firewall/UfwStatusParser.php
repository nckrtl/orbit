<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Domain\Firewall\FirewallPort;
use App\Domain\Firewall\FirewallSource;
use InvalidArgumentException;

final class UfwStatusParser
{
    /** @return list<array{action: string, source: string, port: string, protocol: string, family: string}> */
    public function ownedRules(string $output, string $comment): array
    {
        $shapes = array_values(array_filter(
            $this->ownedRuleShapes($output, $comment),
            static fn (UfwRuleShape $shape): bool => (
                $shape->direction === 'in'
                && $shape->destination === 'any'
                && $shape->port !== 'any'
                && $shape->protocol !== 'any'
                && $shape->inInterface === null
                && $shape->outInterface === null
            ),
        ));

        return array_map(
            static fn (UfwRuleShape $shape): array => [
                'action' => $shape->action,
                'source' => $shape->source,
                'port' => $shape->port,
                'protocol' => $shape->protocol,
                'family' => $shape->family ?? 'v4',
            ],
            $shapes,
        );
    }

    public function ownership(string $output, UfwRuleShape $expected): UfwRuleOwnership
    {
        return $this->ownerships($output, [$expected])[0];
    }

    /**
     * @param  non-empty-list<UfwRuleShape>  $expected
     * @return list<UfwRuleOwnership>
     */
    public function ownerships(string $output, array $expected): array
    {
        $expectedComments = [];
        $matchingLines = [];
        $observed = [];

        foreach ($expected as $shape) {
            $expectedComments[$shape->comment] = true;
            $matchingLines[$shape->comment] = 0;
            $observed[$shape->comment] = [];
        }

        foreach (explode("\n", $output) as $line) {
            $comment = $this->comment($line);

            if ($comment === null || ! array_key_exists($comment, $expectedComments)) {
                continue;
            }

            $matchingLines[$comment]++;
            $rule = $this->parseLine($line, $comment);

            if ($rule instanceof UfwRuleShape) {
                $observed[$comment][] = $rule;
            }
        }

        $resolver = new UfwRuleOwnershipResolver;

        return array_map(
            static fn (UfwRuleShape $shape): UfwRuleOwnership => $resolver->resolve(
                $matchingLines[$shape->comment],
                $observed[$shape->comment],
                $shape,
            ),
            $expected,
        );
    }

    /** @return list<UfwRuleShape> */
    private function ownedRuleShapes(string $output, string $comment): array
    {
        $rules = [];

        foreach (explode("\n", $output) as $line) {
            $rule = $this->parseLine($line, $comment);

            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    private function parseLine(string $line, string $comment): ?UfwRuleShape
    {
        $matches = [];
        $matched = preg_match(
            '/\A(?:\[\s*\d+\]\s+)?(.+?)\s+(ALLOW|DENY)\s+(IN|OUT|FWD)\s+(.+?)(?:\s+#\s*(.*))?\z/D',
            trim($line),
            $matches,
        );

        if ($matched !== 1 || ($matches[5] ?? null) !== $comment) {
            return null;
        }

        $family = str_contains($matches[1], '(v6)') || str_contains($matches[4], '(v6)') ? 'v6' : 'v4';
        $target = $this->target(trim(str_replace(search: '(v6)', replace: '', subject: $matches[1])));
        $source = $this->endpoint(trim(str_replace(search: '(v6)', replace: '', subject: $matches[4])));

        if ($target === null || $source === null) {
            return null;
        }

        $direction = strtolower($matches[3]);
        $sourceInterface = $source['interface'];
        $targetInterface = $target['interface'];
        $inInterface = $direction === 'fwd' ? $sourceInterface : $targetInterface ?? $sourceInterface;
        $outInterface = match ($direction) {
            'out' => $targetInterface ?? $sourceInterface,
            'fwd' => $targetInterface,
            default => null,
        };

        if ($direction === 'out') {
            $inInterface = null;
        }

        return new UfwRuleShape(
            comment: $comment,
            action: strtolower($matches[2]),
            direction: $direction,
            source: $source['endpoint'],
            destination: $target['endpoint'],
            port: $target['port'],
            protocol: $target['protocol'],
            inInterface: $inInterface,
            outInterface: $outInterface,
            family: $family,
        );
    }

    private function comment(string $line): ?string
    {
        $marker = strrpos(haystack: $line, needle: '#');

        if ($marker === false) {
            return null;
        }

        return trim(substr(string: $line, offset: $marker + 1));
    }

    /** @return array{endpoint: string, port: string, protocol: string, interface: ?string}|null */
    private function target(string $value): ?array
    {
        $withInterface = $this->withInterface($value);
        $matches = [];

        if (
            preg_match(
                '/\A(?:(.+?)\s+)?(\d{1,5}(?::\d{1,5})?)\/(tcp|udp)\z/D',
                $withInterface['value'],
                $matches,
            ) === 1
        ) {
            try {
                $endpoint = $this->normalizeEndpoint($matches[1] === '' ? 'any' : $matches[1]);
                $port = FirewallPort::normalize($matches[2]);
            } catch (InvalidArgumentException) {
                return null;
            }

            return [
                'endpoint' => $endpoint,
                'port' => $port,
                'protocol' => $matches[3],
                'interface' => $withInterface['interface'],
            ];
        }

        try {
            $endpoint = $this->normalizeEndpoint($withInterface['value']);
        } catch (InvalidArgumentException) {
            return null;
        }

        return [
            'endpoint' => $endpoint,
            'port' => 'any',
            'protocol' => 'any',
            'interface' => $withInterface['interface'],
        ];
    }

    /** @return array{endpoint: string, interface: ?string}|null */
    private function endpoint(string $value): ?array
    {
        $withInterface = $this->withInterface($value);

        try {
            $endpoint = $this->normalizeEndpoint($withInterface['value']);
        } catch (InvalidArgumentException) {
            return null;
        }

        return [
            'endpoint' => $endpoint,
            'interface' => $withInterface['interface'],
        ];
    }

    /** @return array{value: string, interface: ?string} */
    private function withInterface(string $value): array
    {
        $matches = [];

        if (preg_match('/\A(.+?)\s+on\s+([a-zA-Z0-9_.:-]+)\z/D', $value, $matches) !== 1) {
            return ['value' => $value, 'interface' => null];
        }

        return ['value' => trim($matches[1]), 'interface' => $matches[2]];
    }

    private function normalizeEndpoint(string $value): string
    {
        return FirewallSource::normalize(match ($value) {
            'Anywhere', '0.0.0.0/0', '::/0' => 'any',
            default => trim($value),
        });
    }
}
