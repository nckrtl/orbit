<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Instances\AppProjectionAccessProgram;
use App\Infrastructure\Instances\AppProjectionEnvironmentProgram;
use App\Infrastructure\Instances\AppProjectionServingProgram;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use Symfony\Component\Process\Process;

/** Only privilege/service boundaries are substituted; source and receipt programs execute natively. */
final class LocalAppProjectionServingTransport implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var array<string, string> */
    public array $installed = [];

    public bool $loseFinish = false;

    public bool $failBuild = false;

    public ?string $interruptAt = null;

    public bool $foreignReceipt = false;

    public ?string $foreignAt = null;

    public ?string $accessInterrupt = null;

    public function __construct(private readonly string $root) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        if ($command->arguments === ['sudo', 'python3', '-c', AppProjectionAccessProgram::script()]) {
            $program = str_replace('/var/lib/orbit/app-access', $this->root.'/access-receipts', AppProjectionAccessProgram::script());
            $process = new Process(['python3', '-c', $program]);
            $process->setInput(stream_get_contents($command->protectedInput?->stream()));
            $process->run();

            return new CommandResult($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput(), 0, false);
        }
        if ($command->arguments === ['sudo', 'python3', '-c', AppProjectionServingProgram::script()]) {
            $input = stream_get_contents($command->protectedInput?->stream());
            $program = str_replace(['/var/lib/orbit/app-serving', '/var/lib/orbit/app-access'], [$this->root.'/receipts', $this->root.'/access-receipts'], AppProjectionServingProgram::script());
            $payload = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
            if (in_array($this->accessInterrupt, ['file-command', 'file-init'], true)) {
                $point = $this->accessInterrupt;
                $this->accessInterrupt = null;
                if ($point === 'file-command') {
                    return new CommandResult(255, '', '', 0, false);
                }
                $program = str_replace("root = base / binding['receipt_id']; fresh = False", "os._exit(99); root = base / binding['receipt_id']; fresh = False", $program);
            }
            if ($this->interruptAt !== null && ($this->interruptAt !== 'snapshot-unlink' || $payload['finish'])) {
                $hook = match ($this->interruptAt) {
                    'cache-write' => "publish(record, fd, stage_fd, 'candidate', record['before'], record['result'])",
                    'cache-restore' => "publish(record, fd, stage_fd, 'restore', current, record['restore_result'])",
                    'snapshot-unlink' => 'if current is not None: os.unlink(name, dir_fd=origin_fd); os.fsync(origin_fd)',
                    'prepare-create', 'restore-create' => '# creation checkpoint: the file is still inside the recorded private directory',
                };
                if (in_array($this->interruptAt, ['prepare-create', 'restore-create'], true)) {
                    $key = $this->interruptAt === 'prepare-create' ? 'result' : 'restore_result';
                    $program = str_replace($hook, "if key == '{$key}': os._exit(99)", $program);
                } else {
                    $program = str_replace($hook, $hook.'; os._exit(99)', $program);
                }
                $this->interruptAt = null;
            }
            if ($this->foreignAt !== null) {
                $where = $this->foreignAt;
                $phase = str_starts_with($where, 'prepare-') ? 'prepare' : 'restore';
                $path = json_encode($this->root.'/checkout/new/bootstrap/cache/config.php', JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $mutation = "victim = pathlib.Path({$path}); incoming = victim.with_name('foreign.next'); incoming.write_bytes(b'foreign-cache'); os.chmod(incoming, 0o600); os.replace(incoming, victim)";
                if (str_contains($where, '-parent')) {
                    $mutation = "victim = pathlib.Path({$path}); cache = victim.parent; os.rename(cache, str(cache) + '-held'); cache.mkdir(mode=0o755); victim.write_bytes(b'foreign-cache'); os.chmod(victim, 0o600)";
                } elseif (str_ends_with($where, '-protection')) {
                    $mutation = "os.chmod(pathlib.Path({$path}).parent, 0o775)";
                }
                if (str_ends_with($where, '-exchange')) {
                    $name = $phase === 'prepare' ? 'candidate' : 'restore';
                    $program = str_replace('libc = ctypes.CDLL(None, use_errno=True)', "injected = False\nlibc = ctypes.CDLL(None, use_errno=True)", $program);
                    $hook = 'rc = libc.renameat2';
                    $program = str_replace($hook, "global injected\n    if src_name == '{$name}' and not injected: injected = True; {$mutation}\n    ".$hook, $program);
                } else {
                    $program = str_replace('# before '.$phase.' publication', $mutation, $program);
                }
                $this->foreignAt = null;
            }
            $process = new Process(['python3', '-c', $program]);
            $process->setInput($input);
            $process->run();
            $code = $process->getExitCode() ?? 1;
            if ($this->loseFinish && $payload['finish'] && $code === 0) {
                $this->loseFinish = false;
                $code = 255;
            }

            $output = $process->getOutput();
            if ($this->foreignReceipt && $code === 0) {
                $this->foreignReceipt = false;
                $observed = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
                $observed['projection_id'] = 'ffffffff-ffff-ffff-ffff-ffffffffffff';
                $output = json_encode($observed, JSON_THROW_ON_ERROR);
            }

            return new CommandResult($code, $output, $process->getErrorOutput(), 0, false);
        }
        if ($command->arguments === ['sudo', 'python3', '-c', AppProjectionEnvironmentProgram::script()]) {
            $program = str_replace(['/var/lib/orbit/app-environments', '/var/lib/orbit/app-access'], [$this->root.'/environment-receipts', $this->root.'/access-receipts'], AppProjectionEnvironmentProgram::script());
            if (in_array($this->accessInterrupt, ['file-command', 'file-init'], true)) {
                $point = $this->accessInterrupt;
                $this->accessInterrupt = null;
                if ($point === 'file-command') {
                    return new CommandResult(255, '', '', 0, false);
                }
                $program = str_replace("receipt_id = binding['receipt_id']", "os._exit(99); receipt_id = binding['receipt_id']", $program);
            }
            $process = new Process(['python3', '-c', $program]);
            $process->setInput(stream_get_contents($command->protectedInput?->stream()));
            $process->run();

            return new CommandResult($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput(), 0, false);
        }
        if (str_contains($command->input ?? '', 'checkouts=()')) {
            $encoded = base64_encode(CaddyAclTestIdentity::script());
            $shim = 'identity_program=$(printf %s '.escapeshellarg($encoded).' | base64 --decode)'."\n".<<<'BASH'
                sudo() {
                    if [ "$1" = -n ]; then shift; fi
                    if [ "$1" = -u ] && [ "$2" = caddy ]; then
                        shift 2
                        test "$1" = python3 && test "$2" = -c
                        shift 2
                        command python3 -c "$identity_program" "$@"
                    else
                        command "$@"
                    fi
                }
                BASH;
            $nativeIdentity = new Process(['sudo', '-n', '-u', 'caddy', 'id', '-u']);
            $nativeIdentity->run();
            $process = new Process($command->arguments);
            $guard = str_replace('/var/lib/orbit/app-access', $this->root.'/access-receipts', AppProjectionAccessProgram::script());
            if ($this->accessInterrupt === 'acl-write') {
                $guard = str_replace("operation['complete'] = True; access_save(fd, manifest)", "ao._exit(99); operation['complete'] = True; access_save(fd, manifest)", $guard);
                $this->accessInterrupt = null;
            }
            $script = str_replace(base64_encode(AppProjectionAccessProgram::script()), base64_encode($guard), $command->input);
            $script = str_replace('command sudo -n python3 -c "$access_program"', 'command python3 -c "$access_program"', $script);
            if (! $nativeIdentity->isSuccessful() && str_contains($script, 'access_program=')) {
                $script = str_replace('else command sudo "$@"', 'else fixture_sudo "$@"', $script);
                $shim = str_replace('sudo()', 'fixture_sudo()', $shim);
            }
            if ($this->accessInterrupt === 'access-exit') {
                $script .= "\nkill -KILL $$\n";
                $this->accessInterrupt = null;
            }
            $process->setInput(($nativeIdentity->isSuccessful() ? '' : $shim."\n").$script);
            $process->run();
            $code = $process->getExitCode() ?? 1;
            if ($this->accessInterrupt === 'access-result' && $code === 0) {
                $code = 255;
                $this->accessInterrupt = null;
            }

            return new CommandResult($code, $process->getOutput(), $process->getErrorOutput(), 0, false);
        }
        if (str_contains($command->input ?? '', 'artisan_kind=')) {
            $process = new Process($command->arguments);
            $process->setInput($command->input);
            $process->run();

            return new CommandResult($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput(), 0, false);
        }
        if (str_contains($command->input ?? '', 'expected_root_hash=$4')) {
            return new CommandResult(0, 'CURRENT', '', 0, false);
        }
        if (str_contains($command->input ?? '', 'base64 --wrap=0 -- "$path"')) {
            $output = '';
            foreach ($this->installed as $version => $configuration) {
                $output .= $version."\t".base64_encode($configuration)."\n";
            }

            return new CommandResult(0, $output, '', 0, false);
        }
        if (str_contains($command->input ?? '', 'managed_configuration="$pool_directory/orbit-scopes.conf"')) {
            preg_match("/printf '%s' '([^']*)' \\| base64 --decode/", $command->input, $match);
            $this->installed[$command->arguments[4]] = base64_decode($match[1], true);
        }
        if ($this->failBuild && str_contains($command->input ?? '', 'orbit-caddy-build-stage=')) {
            $this->failBuild = false;

            return new CommandResult(1, '', 'orbit-caddy-build-stage=reload', 0, false);
        }

        return new CommandResult(0, '', '', 0, false);
    }
}
