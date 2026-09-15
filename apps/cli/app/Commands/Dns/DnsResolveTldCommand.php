<?php

declare(strict_types=1);

namespace App\Commands\Dns;

use App\Commands\GatewayCommand;
use App\Services\Dns\ResolvesLocalDns;

final class DnsResolveTldCommand extends GatewayCommand
{
    private const string LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    #[\Override]
    protected $signature = 'dns:resolve
        {tld : Development TLD or exact private Route name, without a leading dot}
        {target? : IP address that the TLD wildcard or exact name resolves to}
        {--reset : Remove the local resolver override}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Configure or remove a local development TLD or exact private Route resolver override.';

    public function handle(ResolvesLocalDns $resolver): int
    {
        $name = $this->stringArgument('tld', 'Development TLD or exact Route name', 'dns.tld_required');

        if ($name === null) {
            return self::FAILURE;
        }

        $kind = $this->nameKind($name);

        if ($kind === null) {
            return $this->invalidNameFailure($name);
        }

        if ($resolver->platform() !== 'macos') {
            return $this->renderGatewayFailure(
                'dns.unsupported_platform',
                'Local resolver overrides require macOS.',
            );
        }

        if ($this->option('reset') === true) {
            return $this->reset($resolver, $name, $kind);
        }

        return $this->resolve($resolver, $name, $kind);
    }

    private function resolve(ResolvesLocalDns $resolver, string $name, string $kind): int
    {
        $target = $this->argument('target');

        if (! is_string($target) || filter_var($target, FILTER_VALIDATE_IP) === false) {
            return $this->renderGatewayFailure(
                'dns.target_invalid',
                'Target must be an IPv4 or IPv6 address.',
            );
        }

        if (! $resolver->available()) {
            return $this->renderGatewayFailure(
                'dns.dnsmasq_missing',
                'Local resolver overrides require Homebrew dnsmasq.',
            );
        }

        $result = $resolver->resolve($name, $target);

        if (in_array($result['status'], ['write_failed', 'refresh_failed'], strict: true)) {
            return $this->resolverFailure($result['status']);
        }

        if ($this->option('json') === true) {
            $this->writeJson($this->successPayload($name, $kind, $target, $result));

            return self::SUCCESS;
        }

        $label = $this->displayName($name, $kind);
        $message = $result['status'] === 'already_resolved'
            ? "{$label} already resolves locally to {$target}."
            : "{$label} resolves locally to {$target}.";
        $this->info($message);

        if ($result['changed']) {
            $this->comment('Restart open browsers to use the new route.');
        }

        return self::SUCCESS;
    }

    private function reset(ResolvesLocalDns $resolver, string $name, string $kind): int
    {
        if ($this->argument('target') !== null) {
            return $this->renderGatewayFailure(
                'dns.target_invalid',
                'Target is not valid with --reset.',
            );
        }

        $result = $resolver->reset($name);

        if (in_array($result['status'], ['write_failed', 'refresh_failed'], strict: true)) {
            return $this->resolverFailure($result['status']);
        }

        if ($this->option('json') === true) {
            $this->writeJson($this->successPayload($name, $kind, null, $result));

            return self::SUCCESS;
        }

        $label = $this->displayName($name, $kind);
        $message = $result['status'] === 'already_absent'
            ? "{$label} resolver override is already absent."
            : "{$label} resolver override removed.";
        $this->info($message);

        if ($result['changed']) {
            $this->comment('Restart open browsers to use the default route.');
        }

        return self::SUCCESS;
    }

    /** @return 'tld'|'hostname'|null */
    private function nameKind(string $name): ?string
    {
        if (preg_match('/\A'.self::LABEL.'\z/D', $name) === 1) {
            return 'tld';
        }

        if (
            strlen($name) <= 253
            && preg_match('/\A'.self::LABEL.'(?:\.'.self::LABEL.')+\z/D', $name) === 1
        ) {
            return 'hostname';
        }

        return null;
    }

    private function invalidNameFailure(string $name): int
    {
        if ($this->looksLikeTld($name)) {
            return $this->renderGatewayFailure(
                'dns.tld_invalid',
                'Development TLD must be one lowercase DNS label without a leading dot.',
            );
        }

        return $this->renderGatewayFailure(
            'dns.hostname_invalid',
            'Exact Route name must be a lowercase multi-label DNS name without a leading dot.',
        );
    }

    private function looksLikeTld(string $name): bool
    {
        return ! str_contains($name, '.')
            || (str_starts_with($name, '.') && ! str_contains(substr($name, 1), '.'));
    }

    private function displayName(string $name, string $kind): string
    {
        return $kind === 'hostname' ? $name : ".{$name}";
    }

    /**
     * @param  array{status: string, changed: bool}  $result
     * @return array{tld?: string, hostname?: string, target: ?string, status: string, changed: bool, restart_browser: bool}
     */
    private function successPayload(string $name, string $kind, ?string $target, array $result): array
    {
        $identity = $kind === 'hostname'
            ? ['hostname' => $name]
            : ['tld' => $name];

        return [
            ...$identity,
            'target' => $target,
            'status' => $result['status'],
            'changed' => $result['changed'],
            'restart_browser' => $result['changed'],
        ];
    }

    private function resolverFailure(string $status): int
    {
        if ($status === 'write_failed') {
            return $this->renderGatewayFailure(
                'dns.write_failed',
                'Could not update local DNS resolver configuration.',
            );
        }

        return $this->renderGatewayFailure(
            'dns.refresh_failed',
            'Local DNS configuration changed, but dnsmasq could not be refreshed.',
        );
    }
}
