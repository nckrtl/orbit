<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskCheckResult;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskSessionClassificationException;
use App\Domain\Tasks\TaskVerificationPolicy;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use Throwable;

final readonly class RemoteTaskCheckRunner implements TaskCheckRunner
{
    public function __construct(private AppDevSshExecutor $ssh) {}

    public function run(AppInstance $instance, array $references): TaskCheckResult
    {
        $data = $this->execute($instance, 'run', base64_encode(json_encode($references, JSON_THROW_ON_ERROR)));
        $identity = $data['identity'] ?? null;
        if (! is_array($identity) || ! is_string($identity['digest'] ?? null)
            || ($data['profile'] ?? null) !== TaskVerificationPolicy::Profile
            || ! is_bool($data['passed'] ?? null) || ! is_array($data['checks'] ?? null)
            || ! is_array($data['evidence'] ?? null) || ! is_numeric($data['seconds'] ?? null)
            || ! is_finite((float) $data['seconds']) || $data['seconds'] < 0) {
            throw new TaskSessionClassificationException('Task checks returned an incomplete result.');
        }
        if ($data['passed']) {
            $expected = [];
            foreach (['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'] as $project) {
                foreach ([['composer', 'validate', '--strict'], ['composer', 'check'], ['composer', 'test:affected']] as $command) {
                    $expected[] = [$project, $command];
                }
            }
            foreach ($expected as $index => [$project, $command]) {
                $check = $data['checks'][$index] ?? [];
                if (($check['project'] ?? null) !== $project || ($check['command'] ?? null) !== $command || ($check['exit_code'] ?? null) !== 0) {
                    throw new TaskSessionClassificationException('Task checks did not complete the required profile.');
                }
            }
            if (($data['unchanged'] ?? null) !== true) {
                throw new TaskSessionClassificationException('Task check inputs changed during execution.');
            }
        }
        foreach ($data['evidence'] as $id => $evidence) {
            $reference = array_find($references, static fn (array $item): bool => $item['criterion_id'] === $id);
            if ($reference === null || ! is_array($evidence) || ($evidence['result'] ?? null) !== 'passed'
                || ($evidence['project'] ?? null) !== $reference['project'] || ($evidence['path'] ?? null) !== $reference['path']
                || ($evidence['test'] ?? null) !== $reference['test'] || ! is_int($evidence['assertions'] ?? null)
                || $evidence['assertions'] < 1 || ! is_string($evidence['source'] ?? null) || strlen($evidence['source']) > 12_000
                || ($evidence['source_digest'] ?? null) !== hash('sha256', $evidence['source'])) {
                throw new TaskSessionClassificationException('Task checks returned invalid test evidence.');
            }
        }

        return new TaskCheckResult($this->digest($identity['digest']), $data['passed'], $data['checks'], $data['evidence'], (float) $data['seconds']);
    }

    public function fingerprint(AppInstance $instance): string
    {
        $data = $this->execute($instance, 'identity');

        return $this->digest($data['digest'] ?? null);
    }

    private function digest(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
            throw new TaskSessionClassificationException('Task checks returned no input fingerprint.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function execute(AppInstance $instance, string $mode, string $references = ''): array
    {
        try {
            $script = file_get_contents(resource_path('tasks/check.py'));
            if ($script === false || $instance->checkout_path === '') {
                throw new TaskSessionClassificationException('Task check runner or workspace is unavailable.');
            }
            $instance->loadMissing('node');
            $result = $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['python3', '-', $instance->checkout_path, $mode, $references],
                input: $script,
                maxOutputBytes: 131_072,
                timeout: $mode === 'run' ? 880.0 : 20.0,
            ), 'task-verification', 'tasks.verification_failed', $mode === 'run' ? 880.0 : 20.0);
            $data = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            if ($result->truncated || ! is_array($data) || isset($data['error'])) {
                throw new TaskSessionClassificationException('Task verification result is unavailable or truncated.');
            }

            return $data;
        } catch (Throwable) {
            throw new TaskSessionClassificationException('Task verification could not read a complete result from the assigned workspace.');
        }
    }
}
