<?php

declare(strict_types=1);

namespace App\Commands\Design;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressState;
use App\Support\Console\PromptAborted;
use App\Support\Console\TerminalText;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\TextPrompt;

/**
 * Design sketch: the intended node:add experience without a Gateway.
 *
 * The sketch prompts for every missing input, reads a made-up host key, and plays
 * a scripted provisioning sequence through the real progress tree. `--outcome`
 * selects where it fails so each refusal can be reviewed and recorded.
 */
final class DesignNodeAddCommand extends GatewayCommand
{
    private const array ROLES = ['app-dev', 'app-prod', 'router', 'ingress', 'database', 'metrics'];

    private const array OUTCOMES = ['success', 'fingerprint-mismatch', 'ssh-timeout', 'package-failure'];

    #[\Override]
    protected $signature = 'design:node-add
        {name? : Node name}
        {host? : Public SSH host}
        {--role=* : Initial role assignment}
        {--tld= : Node TLD; required for the app-dev role outside a Cluster TLD}
        {--user= : Bootstrap SSH user; defaults to root}
        {--host-key-fingerprint= : Approved SSH SHA256 host key fingerprint}
        {--outcome=success : success, fingerprint-mismatch, ssh-timeout, or package-failure}
        {--pace=1 : Seconds each provisioning step takes}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Design sketch of the node:add experience; runs no Gateway request.';

    #[\Override]
    protected $hidden = true;

    public function handle(): int
    {
        $outcome = $this->stringOption('outcome') ?? 'success';
        if (! in_array($outcome, self::OUTCOMES, true)) {
            return $this->renderGatewayFailure('input.invalid', 'Outcome must be one of '.implode(', ', self::OUTCOMES).'.');
        }

        try {
            $inputs = $this->collectInputs();
            if ($inputs === null) {
                return self::FAILURE;
            }

            $fingerprint = $this->approveHostKey($inputs['host'], $outcome);
            if ($fingerprint === null) {
                return self::FAILURE;
            }
        } catch (PromptAborted|ConsoleInterrupted) {
            return $this->renderGatewayFailure('input.cancelled', 'Node creation was cancelled.');
        }

        return $this->provision($inputs, $fingerprint, $outcome);
    }

    /** @return array{name: string, host: string, roles: list<string>, tld: ?string, user: string}|null */
    private function collectInputs(): ?array
    {
        $name = $this->optionalArgument('name');
        if ($name === null) {
            $name = $this->promptOrRefuse('The Node name is required.', fn (): TextPrompt => new TextPrompt(
                'Node name',
                placeholder: 'beast',
                required: true,
                validate: self::nameError(...),
                hint: 'Lowercase letters, digits, and hyphens.',
            ));
            if ($name === null) {
                return null;
            }
        }

        $host = $this->optionalArgument('host');
        if ($host === null) {
            $host = $this->promptOrRefuse('The SSH host is required.', fn (): TextPrompt => new TextPrompt(
                'SSH host',
                placeholder: 'beast.example.com or 203.0.113.10',
                required: true,
                hint: 'The Gateway connects to port 22 as the bootstrap user.',
            ));
            if ($host === null) {
                return null;
            }
        }

        $roles = array_values(array_filter($this->option('role'), 'is_string'));
        if ($roles === []) {
            $selected = $this->promptOrRefuse('At least one --role is required.', fn (): MultiSelectPrompt => new MultiSelectPrompt(
                'Roles',
                options: self::ROLES,
                default: ['app-dev'],
                required: true,
                hint: 'Space selects, enter confirms.',
            ));
            if ($selected === null) {
                return null;
            }
            $roles = array_values(array_filter(is_array($selected) ? $selected : [], 'is_string'));
        }

        $tld = $this->stringOption('tld');
        if ($tld === null && in_array('app-dev', $roles, true)) {
            $tld = $this->promptOrRefuse('An app-dev TLD is required for node ['.$name.'].', fn (): TextPrompt => new TextPrompt(
                'Development TLD',
                placeholder: 'test',
                default: 'test',
                required: true,
                validate: self::tldError(...),
                hint: 'Generated development domains end in this TLD.',
            ), 'node.tld_required');
            if ($tld === null) {
                return null;
            }
        }

        $user = $this->stringOption('user');
        if ($user === null) {
            $user = $this->consoleMode()->mayPrompt
                ? $this->promptOrRefuse('', fn (): TextPrompt => new TextPrompt(
                    'Bootstrap SSH user',
                    default: 'root',
                    required: true,
                    hint: 'Orbit creates the managed user orbit through this account. Name another user when root cannot log in.',
                ))
                : 'root';
            if ($user === null) {
                return null;
            }
        }

        if (! is_string($name) || ! is_string($host) || ! is_string($user) || ($tld !== null && ! is_string($tld))) {
            return null;
        }

        return ['name' => $name, 'host' => $host, 'roles' => $roles, 'tld' => $tld, 'user' => $user];
    }

    private function optionalArgument(string $argument): ?string
    {
        $value = $this->argument($argument);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Prompt for a value in a terminal; refuse with the given message and return null otherwise.
     *
     * @param  \Closure(): Prompt  $makePrompt
     */
    private function promptOrRefuse(string $refusal, \Closure $makePrompt, string $code = 'input.invalid'): mixed
    {
        if (! $this->consoleMode()->mayPrompt) {
            $this->renderGatewayFailure($code, $refusal);

            return null;
        }

        return $this->commandPrompts()->run($makePrompt);
    }

    private function approveHostKey(string $host, string $outcome): ?string
    {
        $observed = self::fakeFingerprint($outcome === 'fingerprint-mismatch' ? $host.'-rotated' : $host);
        $approved = $this->stringOption('host-key-fingerprint');

        if ($approved === null && ! $this->consoleMode()->mayPrompt) {
            $this->renderGatewayFailure('node.ssh_host_fingerprint_required', 'An expected SSH host fingerprint is required for node ['.$host.'].');

            return null;
        }

        $this->spinnerDisplay()->during('Reading the host key of '.$host, fn () => $this->pause());

        if ($approved !== null) {
            if ($approved === $observed) {
                return $observed;
            }

            $this->renderGatewayFailure(
                'node.ssh_host_key_mismatch',
                'The host key of '.$host.' is '.$observed.', not the approved '.$approved.'.',
                humanMessage: 'Check the machine before you retry. A changed host key can mean a rebuilt machine or an impostor.',
            );

            return null;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Host key of '.$host, [
            'Type' => 'ED25519',
            'Fingerprint' => $observed,
        ]));

        $trusted = $this->commandPrompts()->run(fn (): ConfirmPrompt => new ConfirmPrompt(
            TerminalText::safe('Trust this host key for '.$host.'?'),
            default: false,
            hint: 'Compare it with ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub on the machine.',
        ));

        if ($trusted !== true) {
            $this->renderGatewayFailure('input.cancelled', 'The host key of '.$host.' was not trusted. Nothing was changed.');

            return null;
        }

        return $observed;
    }

    /** @param array{name: string, host: string, roles: list<string>, tld: ?string, user: string} $inputs */
    private function provision(array $inputs, string $fingerprint, string $outcome): int
    {
        $wireguardIp = '10.44.0.'.(4 + (crc32($inputs['name']) % 200));
        $steps = [
            'connect' => ['Connect over SSH', 'Connecting to '.$inputs['host'].' as '.$inputs['user'], 'Connected to '.$inputs['host']],
            'user' => ['Create the managed user', 'Creating the managed user orbit', 'Created the managed user orbit'],
            'packages' => ['Install base packages', 'Installing base packages', 'Installed base packages'],
            'ssh' => ['Harden SSH', 'Hardening SSH', 'Hardened SSH; public login stays open for recovery'],
            'wireguard' => ['Install WireGuard', 'Installing WireGuard', 'Installed WireGuard'],
            'vpn' => ['Join the VPN', 'Joining the VPN', 'Joined the VPN as '.$wireguardIp],
            'dns' => ['Configure private DNS', 'Configuring private DNS', 'Configured private DNS'],
        ];
        foreach ($inputs['roles'] as $role) {
            $steps['role-'.$role] = ['Converge the '.$role.' role', 'Converging the '.$role.' role', 'Converged the '.$role.' role'];
        }
        $failures = match ($outcome) {
            'ssh-timeout' => ['connect' => ['node.ssh_unreachable', 'Connection to '.$inputs['host'].':22 timed out after 30 seconds.']],
            'package-failure' => ['packages' => ['node.bootstrap_failed', 'apt-get install exited with status 100 while installing curl.']],
            default => [],
        };

        $progress = $this->progressDisplay('Add Node '.$inputs['name']);
        foreach ($steps as $id => [$waiting, $running, $completed]) {
            $progress->admit($id, $waiting, $running, $completed);
        }

        foreach (array_keys($steps) as $id) {
            $failure = $progress->during($id, function () use ($id, $failures): ?array {
                $this->pause();

                return $failures[$id] ?? null;
            });

            if ($failure !== null) {
                [$code, $message] = $failure;
                $progress->complete($id, ProgressState::Failure, $message);
                $progress->finish('Node '.$inputs['name'].' was not added.');

                return $this->renderGatewayFailure($code, $message, humanMessage: 'Fix the machine and run the same command again; the Gateway resumes at the failed step.');
            }

            $progress->complete($id, ProgressState::Success);
        }

        $progress->finish('Added Node '.$inputs['name'].'.');

        $node = [
            'name' => $inputs['name'],
            'host' => $inputs['host'],
            'roles' => $inputs['roles'],
            'tld' => $inputs['tld'],
            'wireguard_ip' => $wireguardIp,
            'ssh_host_fingerprint' => $fingerprint,
            'status' => 'active',
        ];

        if ($this->consoleMode()->machine) {
            $this->writeJson(['node' => $node]);

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Node '.$inputs['name'], [
            'Roles' => implode(', ', $inputs['roles']),
            'TLD' => $inputs['tld'] ?? '—',
            'WireGuard IP' => $wireguardIp,
            'SSH' => 'orbit@'.$wireguardIp.' over the VPN; public SSH closes with the first role',
        ]));

        return self::SUCCESS;
    }

    private function pause(): void
    {
        $pace = (float) ($this->stringOption('pace') ?? '1');
        if ($pace > 0) {
            usleep((int) ($pace * 1_000_000));
        }
    }

    private static function fakeFingerprint(string $seed): string
    {
        return 'SHA256:'.substr(str_replace(['=', '/', '+'], ['', 'a', 'b'], base64_encode(hash('sha256', 'design:'.$seed, true))), 0, 43);
    }

    private static function nameError(string $name): ?string
    {
        return preg_match('/\A[a-z0-9]([a-z0-9-]{0,62})\z/', $name) === 1 && ! str_ends_with($name, '-')
            ? null
            : 'Use 1 to 63 lowercase letters, digits, or hyphens, starting with a letter or digit.';
    }

    private static function tldError(string $tld): ?string
    {
        return preg_match('/\A[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\z/', $tld) === 1
            ? null
            : 'Use lowercase labels such as test or dev.internal, without a leading dot.';
    }
}
