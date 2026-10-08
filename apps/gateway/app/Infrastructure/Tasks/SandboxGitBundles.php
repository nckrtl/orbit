<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\Tasks\TaskPullRequestException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/** Credentials stay in the Gateway. Guest bytes become trusted only after bundle verification. */
final readonly class SandboxGitBundles
{
    private const int MaximumBytes = 512 * 1024 * 1024;

    public function __construct(private TaskWorkspaceExecutor $guest, private ProcessRunner $processes) {}

    public function publish(Instance $workspace, GitHubRepository $repository, int $groupId, string $commit, #[SensitiveParameter] string $token): void
    {
        if ($workspace->task_sandbox_id === null || $workspace->taskSandbox?->group_id !== $groupId || ! $this->commit($commit)) {
            throw new TaskPullRequestException('The approved sandbox publication identity is invalid.');
        }
        $transfer = (string) Str::uuid();
        $temporary = $this->temporary();
        $path = $temporary.'/objects.bundle';
        try {
            $export = $this->jsonGuest($workspace, ['operation' => 'export', 'id' => $transfer, 'checkout' => $workspace->checkout_path, 'commit' => $commit]);
            $size = $export['size'] ?? null;
            $digest = $export['sha256'] ?? null;
            if (! is_int($size) || $size < 1 || $size > self::MaximumBytes || ! is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
                throw new TaskPullRequestException('The sandbox bundle size or digest is invalid.');
            }
            $file = fopen($path, 'x+b');
            if ($file === false) {
                throw new TaskPullRequestException('The received bundle could not be stored.');
            }
            try {
                chmod($path, 0600);
                $hash = hash_init('sha256');
                for ($offset = 0; $offset < $size;) {
                    $length = min(4 * 1024 * 1024, $size - $offset);
                    $chunk = $this->callGuest($workspace, ['operation' => 'read', 'id' => $transfer, 'offset' => $offset, 'length' => $length], $length);
                    if (strlen($chunk) !== $length || fwrite($file, $chunk) !== $length) {
                        throw new TaskPullRequestException('The sandbox bundle transfer was incomplete.');
                    }
                    hash_update($hash, $chunk);
                    $offset += $length;
                }
                if (! hash_equals($digest, hash_final($hash))) {
                    throw new TaskPullRequestException('The sandbox bundle changed during transfer.');
                }
            } finally {
                fclose($file);
            }
            $published = $this->trusted(['operation' => 'publish', 'bundle' => $path, 'commit' => $commit,
                'repository' => $repository->owner.'/'.$repository->name, 'group' => $groupId, 'token' => $token]);
            if (($published['commit'] ?? null) !== $commit || ($published['branch'] ?? null) !== 'task-'.$groupId) {
                throw new TaskPullRequestException('The trusted publisher returned a different commit or branch.');
            }
        } catch (Throwable $exception) {
            throw new TaskPullRequestException('The sandbox branch could not be published through the trusted bundle broker.', previous: $exception);
        } finally {
            $this->cleanup($workspace, $transfer, $temporary);
        }
    }

    public function fetch(Instance $workspace, GitHubRepository $repository, string $branch, GitReadEnvironment $environment, bool $missingOk = false): void
    {
        if ($workspace->task_sandbox_id === null || ! GitBranchName::isValid($branch)) {
            throw new TaskPullRequestException('The sandbox fetch identity is invalid.');
        }
        $transfer = (string) Str::uuid();
        $temporary = $this->temporary();
        $path = $temporary.'/objects.bundle';
        try {
            $fetched = $this->trusted(['operation' => 'fetch', 'bundle' => $path, 'repository' => $repository->owner.'/'.$repository->name, 'branch' => $branch, 'environment' => $environment->variables, 'missing_ok' => $missingOk]);
            if (($fetched['missing'] ?? null) === true && $missingOk) {
                $this->guest->execute($workspace, new RemoteCommand(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', $workspace->checkout_path, 'update-ref', '-d', 'refs/remotes/origin/'.$branch]), 'task-bundle-missing-ref', 'tasks.bundle_failed');

                return;
            }
            $commit = $fetched['commit'] ?? null;
            $size = $fetched['size'] ?? null;
            if (! is_string($commit) || ! $this->commit($commit) || ! is_int($size) || $size < 1 || $size > self::MaximumBytes
                || is_link($path) || ! is_file($path) || filesize($path) !== $size) {
                throw new TaskPullRequestException('The trusted fetch returned an invalid bundle.');
            }
            $started = $this->jsonGuest($workspace, ['operation' => 'begin', 'id' => $transfer]);
            if (($started['offset'] ?? null) !== 0) {
                throw new TaskPullRequestException('The sandbox transfer did not start.');
            }
            $file = fopen($path, 'rb');
            if ($file === false) {
                throw new TaskPullRequestException('The fetched bundle could not be read.');
            }
            try {
                for ($offset = 0; $offset < $size;) {
                    $length = min(256 * 1024, $size - $offset);
                    $chunk = fread($file, 256 * 1024);
                    if ($chunk === false || strlen($chunk) !== $length) {
                        throw new TaskPullRequestException('The fetched bundle is incomplete.');
                    }
                    $written = $this->jsonGuest($workspace, ['operation' => 'write', 'id' => $transfer, 'offset' => $offset, 'data' => base64_encode($chunk)]);
                    $offset += $length;
                    if (($written['offset'] ?? null) !== $offset) {
                        throw new TaskPullRequestException('The sandbox bundle chunk was not accepted.');
                    }
                }
            } finally {
                fclose($file);
            }
            $ref = 'refs/remotes/origin/'.$branch;
            $imported = $this->jsonGuest($workspace, ['operation' => 'import', 'id' => $transfer, 'checkout' => $workspace->checkout_path, 'commit' => $commit, 'ref' => $ref]);
            if (($imported['commit'] ?? null) !== $commit || ($imported['ref'] ?? null) !== $ref) {
                throw new TaskPullRequestException('The sandbox imported a different commit or branch.');
            }
        } catch (Throwable $exception) {
            throw new TaskPullRequestException('The sandbox base branch could not be fetched through the trusted bundle broker.', previous: $exception);
        } finally {
            $this->cleanup($workspace, $transfer, $temporary);
        }
    }

    /** @param array<string, mixed> $request */
    private function callGuest(Instance $workspace, array $request, int $limit = 65536): string
    {
        $program = file_get_contents(resource_path('compute/guest-git-bundle.py'));
        if (! is_string($program) || $program === '') {
            throw new TaskPullRequestException('The guest bundle program is unavailable.');
        }
        $result = $this->guest->execute($workspace, new RemoteCommand(['python3', '-I', '-c', $program],
            input: json_encode($request, JSON_THROW_ON_ERROR), timeout: 600, maxOutputBytes: $limit), 'task-bundle', 'tasks.bundle_failed');
        if ($result->truncated) {
            throw new TaskPullRequestException('The guest bundle response exceeded its limit.');
        }

        return $result->stdout;
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function jsonGuest(Instance $workspace, array $request): array
    {
        return $this->object($this->callGuest($workspace, $request));
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function trusted(#[SensitiveParameter] array $request): array
    {
        $result = $this->processes->run(new ProcessInvocation(
            ['python3', '-I', resource_path('compute/trusted-git-bundle.py')], timeout: 900,
            protectedInput: ProtectedInput::fromString(json_encode($request, JSON_THROW_ON_ERROR)), maxOutputBytes: 65536,
        ));
        if (! $result->succeeded() || $result->truncated) {
            throw new TaskPullRequestException('The trusted bundle operation failed.');
        }

        return $this->object($result->stdout);
    }

    /** @return array<string, mixed> */
    private function object(string $json): array
    {
        $result = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($result) || array_is_list($result)) {
            throw new TaskPullRequestException('The bundle response is invalid.');
        }
        $object = [];
        foreach ($result as $key => $value) {
            if (! is_string($key)) {
                throw new TaskPullRequestException('The bundle response is invalid.');
            }
            $object[$key] = $value;
        }

        return $object;
    }

    private function temporary(): string
    {
        $path = sys_get_temp_dir().'/orbit-task-bundle-'.Str::uuid();
        if (! mkdir($path, 0700)) {
            throw new TaskPullRequestException('The trusted bundle directory could not be created.');
        }

        return $path;
    }

    private function cleanup(Instance $workspace, string $transfer, string $temporary): void
    {
        try {
            $this->jsonGuest($workspace, ['operation' => 'remove', 'id' => $transfer]);
        } catch (Throwable) {
            // An unreachable guest retains only Git objects. Sandbox destruction removes interrupted transfers.
        }
        $path = $temporary.'/objects.bundle';
        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }
        rmdir($temporary);
    }

    private function commit(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/D', $value) === 1;
    }
}
