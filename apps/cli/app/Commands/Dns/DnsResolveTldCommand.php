<?php

declare(strict_types=1);

namespace App\Commands\Dns;

use App\Commands\GatewayCommand;
use App\Services\Dns\ResolvesLocalDns;

/** @mago-expect lint:cyclomatic-complexity Resolve and reset share one exclusive public command contract. */
final class DnsResolveTldCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'dns:resolve
        {tld : Development TLD to configure, without a leading dot}
        {target? : IP address that wildcard hostnames under the TLD resolve to}
        {--reset : Remove the local resolver override for the TLD}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Configure or remove a local development TLD resolver override.';

    public function handle(ResolvesLocalDns $resolver): int
    {
        $tld = $this->stringArgument('tld', 'Development TLD', 'dns.tld_required');

        if ($tld === null) {
            return self::FAILURE;
        }

        if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $tld) !== 1) {
            return $this->renderGatewayFailure(
                'dns.tld_invalid',
                'Development TLD must be one lowercase DNS label without a leading dot.',
            );
        }

        if ($resolver->platform() !== 'macos') {
            return $this->renderGatewayFailure(
                'dns.unsupported_platform',
                'Local TLD resolver overrides require macOS.',
            );
        }

        if ($this->option('reset') === true) {
            return $this->reset($resolver, $tld);
        }

        return $this->resolve($resolver, $tld);
    }

    private function resolve(ResolvesLocalDns $resolver, string $tld): int
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
                'Local TLD resolver overrides require Homebrew dnsmasq.',
            );
        }

        $result = $resolver->resolve($tld, $target);

        if (in_array($result['status'], ['write_failed', 'refresh_failed'], strict: true)) {
            return $this->resolverFailure($result['status']);
        }

        if ($this->option('json') === true) {
            $this->writeJson([
                'tld' => $tld,
                'target' => $target,
                'status' => $result['status'],
                'changed' => $result['changed'],
                'restart_browser' => $result['changed'],
            ]);

            return self::SUCCESS;
        }

        $message = $result['status'] === 'already_resolved'
            ? ".{$tld} already resolves locally to {$target}."
            : ".{$tld} resolves locally to {$target}.";
        $this->info($message);

        if ($result['changed']) {
            $this->comment('Restart open browsers to use the new route.');
        }

        return self::SUCCESS;
    }

    private function reset(ResolvesLocalDns $resolver, string $tld): int
    {
        if ($this->argument('target') !== null) {
            return $this->renderGatewayFailure(
                'dns.target_invalid',
                'Target is not valid with --reset.',
            );
        }

        $result = $resolver->reset($tld);

        if (in_array($result['status'], ['write_failed', 'refresh_failed'], strict: true)) {
            return $this->resolverFailure($result['status']);
        }

        if ($this->option('json') === true) {
            $this->writeJson([
                'tld' => $tld,
                'target' => null,
                'status' => $result['status'],
                'changed' => $result['changed'],
                'restart_browser' => $result['changed'],
            ]);

            return self::SUCCESS;
        }

        $message = $result['status'] === 'already_absent'
            ? ".{$tld} resolver override is already absent."
            : ".{$tld} resolver override removed.";
        $this->info($message);

        if ($result['changed']) {
            $this->comment('Restart open browsers to use the default route.');
        }

        return self::SUCCESS;
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
