<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Commands\GatewayCommand;

/** @mago-expect lint:cyclomatic-complexity Firewall options require independent fail-closed validation. */
abstract class FirewallCommand extends GatewayCommand
{
    protected function nodeId(): ?int
    {
        $node = filter_var(
            $this->option('node'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (is_int($node)) {
            return $node;
        }

        $this->renderGatewayFailure(
            'firewall.node_id_invalid',
            'Node ID must be a positive integer.',
        );

        return null;
    }

    protected function ruleName(): ?string
    {
        $name = $this->stringArgument(
            'name',
            'Firewall rule name',
            'firewall.rule_name_required',
        );

        if ($name !== null && preg_match('/\A[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\z/D', $name) === 1) {
            return $name;
        }

        if ($name !== null) {
            $this->renderGatewayFailure(
                'firewall.rule_name_invalid',
                'Firewall rule name is invalid.',
            );
        }

        return null;
    }

    protected function protocol(): string|false|null
    {
        $protocol = $this->stringOption('protocol');

        if ($protocol === null) {
            return null;
        }

        if (in_array($protocol, ['tcp', 'udp'], strict: true)) {
            return $protocol;
        }

        $this->renderGatewayFailure(
            'firewall.protocol_invalid',
            'Firewall protocol must be tcp or udp.',
        );

        return false;
    }

    protected function source(): string|false|null
    {
        $source = $this->stringOption('from');

        if ($source === null) {
            return null;
        }

        if ($source === 'any') {
            return $source;
        }

        $parts = explode('/', $source, limit: 2);
        $address = $parts[0];
        $prefix = $parts[1] ?? null;
        $packedAddress = inet_pton($address);

        if ($packedAddress === false) {
            $this->renderInvalidSource();

            return false;
        }

        if ($prefix === null) {
            return $source;
        }

        if (
            preg_match('/\A(?:0|[1-9]\d{0,2})\z/D', $prefix) === 1
            && (int) $prefix <= (strlen($packedAddress) * 8)
        ) {
            return $source;
        }

        $this->renderInvalidSource();

        return false;
    }

    protected function port(): ?string
    {
        $port = $this->stringOption('port');
        $matches = [];

        if ($port === null || preg_match('/\A(\d{1,5})(?::(\d{1,5}))?\z/D', $port, $matches) !== 1) {
            $this->renderInvalidPort();

            return null;
        }

        $start = (int) $matches[1];
        $end = ($matches[2] ?? null) !== null && $matches[2] !== '' ? (int) $matches[2] : $start;

        if ($start >= 1 && $start <= 65_535 && $end >= $start && $end <= 65_535) {
            return $port;
        }

        $this->renderInvalidPort();

        return null;
    }

    private function renderInvalidSource(): void
    {
        $this->renderGatewayFailure(
            'firewall.source_invalid',
            'Firewall source must be any or a valid IPv4 or IPv6 address or CIDR.',
        );
    }

    private function renderInvalidPort(): void
    {
        $this->renderGatewayFailure(
            'firewall.port_invalid',
            'Firewall port must be from 1 to 65535 or an ordered range.',
        );
    }
}
