<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Infrastructure\Processes\ProcessInvocation;

/**
 * Retires the stock dnsmasq snippets that conflict with the managed Orbit
 * fragment.
 *
 * The `ubuntu-fan` package ships `/etc/dnsmasq.d/ubuntu-fan`, which sets
 * `bind-interfaces`. dnsmasq reads every file in its conf directory and no
 * file can unset an option another file set, so that snippet and the managed
 * `bind-dynamic` fragment cannot both be present: dnsmasq refuses to start
 * with "cannot set --bind-interfaces and --bind-dynamic". `dnsmasq --test`
 * accepts the pair, so only a restart exposes the conflict.
 *
 * The snippet moves outside the conf directory instead of being deleted or
 * renamed in place. A renamed file still counts, because the conf directory
 * skips only the suffixes named on the `conf-dir` line of `/etc/dnsmasq.conf`.
 */
final readonly class RetiredDnsmasqSnippets
{
    /** @var non-empty-list<string> */
    public const array NAMES = ['ubuntu-fan'];

    public function __construct(
        private string $confDirectory = '/etc/dnsmasq.d',
        private string $retiredDirectory = '/var/lib/orbit/dnsmasq/disabled',
    ) {}

    public function path(string $snippet): string
    {
        return "{$this->confDirectory}/{$snippet}";
    }

    public function retiredPath(string $snippet): string
    {
        return "{$this->retiredDirectory}/{$snippet}";
    }

    /** @return non-empty-list<string> */
    public function arguments(): array
    {
        return ['sudo', 'bash', '-seu', '--', $this->confDirectory, $this->retiredDirectory, ...self::NAMES];
    }

    public function script(): string
    {
        return <<<'BASH'
            conf_directory=$1
            retired_directory=$2
            shift 2
            for snippet in "$@"; do
                stock=$conf_directory/$snippet
                if [ ! -e "$stock" ]; then
                    continue
                fi
                install -d -o root -g root -m 0755 -- "$retired_directory"
                mv -fT -- "$stock" "$retired_directory/$snippet"
            done
            BASH;
    }

    public function invocation(): ProcessInvocation
    {
        return new ProcessInvocation(
            arguments: $this->arguments(),
            timeout: 60.0,
            input: $this->script(),
        );
    }
}
