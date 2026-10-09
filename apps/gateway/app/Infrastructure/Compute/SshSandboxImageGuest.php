<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxImageGuest;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use Throwable;

final readonly class SshSandboxImageGuest implements SandboxImageGuest
{
    private const string CloudInitDone = <<<'PY'
        import json, subprocess, sys
        r = subprocess.run(['cloud-init', 'status', '--format=json'], capture_output=True, text=True, timeout=15)
        try:
            data = json.loads(r.stdout)
        except ValueError:
            data = {}
        print(json.dumps({'done': data.get('status') == 'done' and not data.get('errors')}))
        PY;

    private const string Smoke = <<<'PY'
        import json, os, pwd, subprocess, sys
        r = subprocess.run(['cloud-init', 'status', '--format=json'], capture_output=True, text=True, timeout=15)
        try:
            status = json.loads(r.stdout)
        except ValueError:
            status = {}
        mount = subprocess.run(['findmnt', '-n', '-o', 'FSTYPE,SOURCE', '/home/orbit/orbit'], capture_output=True, text=True, timeout=15).stdout.split()
        try:
            stat = os.stat('/home/orbit/orbit')
            owner = pwd.getpwuid(stat.st_uid).pw_name
            mode = stat.st_mode & 0o777
        except (OSError, KeyError):
            owner, mode = None, None
        try:
            pwd.getpwnam('orbit-worker')
            worker = True
        except KeyError:
            worker = False
        if status.get('status') in ('running', 'not started', 'not run'):
            print(json.dumps({'state': 'running'}))
            sys.exit(0)
        done = status.get('status') == 'done' and not status.get('errors')
        passed = done and mount == ['zfs', 'orbit/checkout'] and owner == 'orbit' and mode == 0o700 and not worker
        print(json.dumps({'state': 'passed' if passed else 'failed'}))
        PY;

    private const string Upload = <<<'PY'
        import json, os, re, shutil, sys
        root = '/var/tmp/orbit-warm/projects'
        projects = json.load(sys.stdin)
        shutil.rmtree(root, ignore_errors=True)
        os.makedirs(root, mode=0o700)
        for slug, files in projects.items():
            if not re.fullmatch(r'[a-z0-9][a-z0-9-]{0,62}', slug) or not set(files) <= {'composer.json', 'composer.lock', 'package.json', 'package-lock.json'}:
                sys.exit(1)
            os.mkdir(os.path.join(root, slug), 0o700)
            for name, text in files.items():
                with open(os.path.join(root, slug, name), 'x', encoding='utf-8') as handle:
                    handle.write(text)
        print(json.dumps({'uploaded': len(projects)}))
        PY;

    public function __construct(private SshExecutor $ssh, private KnownHostsStore $hosts, private SshKeyProvider $keys) {}

    public function cloudInitDone(string $address, HostKey $key): bool
    {
        return ($this->json($address, $key, ['sudo', '-n', 'python3', '-I', '-c', self::CloudInitDone], 30)['done'] ?? null) === true;
    }

    public function unitState(string $address, HostKey $key, string $unit): string
    {
        $result = $this->run($address, $key, new RemoteCommand(['systemctl', 'show', $this->unit($unit).'.service', '--property=LoadState,ActiveState,SubState,Result'],
            timeout: 30, maxOutputBytes: 1024));
        $values = [];
        foreach (explode("\n", trim($result->stdout)) as $line) {
            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            $values[$name] = $value;
        }

        return match (true) {
            ($values['LoadState'] ?? null) === 'not-found' => 'missing',
            ($values['ActiveState'] ?? null) === 'failed' => 'failed',
            ($values['ActiveState'] ?? null) === 'active' && ($values['SubState'] ?? null) === 'exited' => ($values['Result'] ?? null) === 'success' ? 'succeeded' : 'failed',
            in_array($values['ActiveState'] ?? null, ['active', 'activating', 'reloading', 'deactivating'], true) => 'running',
            default => throw new ComputeException('compute.image_guest_failed', 'The build VM reported an unknown unit state.'),
        };
    }

    public function startUnit(string $address, HostKey $key, string $unit, string $script, string $argument): void
    {
        $unit = $this->unit($unit);
        if (preg_match('/\A[a-z]+\z/D', $argument) !== 1) {
            throw new ComputeException('compute.image_guest_failed', 'The build step argument is invalid.');
        }
        // systemd-run refuses a unit name that exists, so a retried start never runs the script twice at once.
        $this->run($address, $key, new RemoteCommand(['sudo', '-n', 'sh', '-c',
            'set -eu; umask 077; cat > /root/'.$unit.'.sh; systemd-run --quiet --unit='.$unit.' --property=RemainAfterExit=yes /bin/sh /root/'.$unit.'.sh '.$argument],
            input: $script, timeout: 60, maxOutputBytes: 4096));
    }

    public function unitLog(string $address, HostKey $key, string $unit): string
    {
        return $this->run($address, $key, new RemoteCommand(['sudo', '-n', 'journalctl', '--no-pager', '--output=cat', '-n', '40', '-u', $this->unit($unit).'.service'],
            timeout: 30, maxOutputBytes: 16384))->stdout;
    }

    public function uploadCaches(string $address, HostKey $key, array $projects): void
    {
        $this->json($address, $key, ['sudo', '-n', 'python3', '-I', '-c', self::Upload], 120, json_encode((object) $projects, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function cacheResults(string $address, HostKey $key): array
    {
        $result = $this->run($address, $key, new RemoteCommand(['sudo', '-n', 'cat', '/var/tmp/orbit-warm/results'], timeout: 30, maxOutputBytes: 65536));
        $outcomes = [];
        foreach (explode("\n", trim($result->stdout)) as $line) {
            if (preg_match('/\A([a-z0-9][a-z0-9-]{0,62}) (composer|npm) (ok|failed|unavailable)\z/D', $line, $match) === 1) {
                $outcomes[$match[1]][$match[2]] = $match[3];
            }
        }

        return $outcomes;
    }

    public function clean(string $address, HostKey $key, string $script): void
    {
        $result = $this->run($address, $key, new RemoteCommand(['sudo', '-n', 'sh', '-c', 'set -eu; umask 077; cat > /root/orbit-image-clean.sh; exec /bin/sh /root/orbit-image-clean.sh clean'],
            input: $script, timeout: 300, maxOutputBytes: 65536));
        if (! str_ends_with(trim($result->stdout), 'orbit-image: clean')) {
            throw new ComputeException('compute.image_clean_failed', 'The build VM did not confirm its identity cleanup.');
        }
    }

    public function smokeState(string $address, HostKey $key): string
    {
        $state = $this->json($address, $key, ['sudo', '-n', 'python3', '-I', '-c', self::Smoke], 30)['state'] ?? null;
        if (! in_array($state, ['running', 'passed', 'failed'], true)) {
            throw new ComputeException('compute.image_guest_failed', 'The smoke VM returned an invalid answer.');
        }

        return $state;
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function json(string $address, HostKey $key, array $arguments, int $timeout, ?string $input = null): array
    {
        $result = $this->run($address, $key, new RemoteCommand($arguments, input: $input, timeout: $timeout, maxOutputBytes: 4096));
        $json = json_decode(trim($result->stdout), true);
        if (! is_array($json) || array_is_list($json) && $json !== []) {
            throw new ComputeException('compute.image_guest_failed', 'The build VM returned an invalid answer.');
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    private function run(string $address, HostKey $key, RemoteCommand $command): CommandResult
    {
        try {
            $this->hosts->put($address, 22, $key);
            $result = $this->ssh->execute(new SshConnection($address, 'orbit', 22, $this->keys->privateKeyPath(), $this->hosts->path(),
                commandTimeout: $command->timeout ?? 60, shareConnection: false), $command);
        } catch (Throwable) {
            throw new ComputeException('compute.image_guest_unreachable', 'The Gateway could not reach the build VM over SSH.');
        }
        if (! $result->succeeded() || $result->truncated) {
            throw new ComputeException('compute.image_guest_failed', 'A command on the build VM failed.');
        }

        return $result;
    }

    private function unit(string $unit): string
    {
        if (preg_match('/\Aorbit-image-[a-z]+\z/D', $unit) !== 1) {
            throw new ComputeException('compute.image_guest_failed', 'The build unit name is invalid.');
        }

        return $unit;
    }
}
