<?php

declare(strict_types=1);

namespace App\Actions\AppInstances\Dependencies;

use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\Dependencies\CollectedDependencyFiles;
use App\Domain\AppInstances\Dependencies\DependencyCollectionException;
use App\Infrastructure\AppInstances\DependencyFilesProgram;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AppInstance;
use Throwable;

final readonly class CollectInstanceDependencyFilesAction
{
    private const array ERRORS = ['dependencies.source_unavailable', 'dependencies.unsafe_source', 'dependencies.unreadable_source', 'dependencies.source_changed', 'dependencies.source_too_large'];

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function execute(AppInstance $instance): CollectedDependencyFiles
    {
        $node = $instance->node;
        $production = $instance->environment === 'production';
        $path = $production ? $instance->production_home : $instance->checkout_path;
        $user = $production ? $instance->production_user : $node->user;
        if (! in_array($instance->environment, ['development', 'production'], true)
            || ! in_array($instance->source_layout, array_column(AppInstanceSourceLayout::cases(), 'value'), true)
            || $instance->migration_required
            || ! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, "\0")
            || ! is_string($user) || preg_match('/\A[a-z_][a-z0-9_-]*\z/D', $user) !== 1
            || ($production && $path !== '/home/'.$user)
            || ! is_string($node->wireguard_ip) || filter_var($node->wireguard_ip, FILTER_VALIDATE_IP) === false) {
            throw new DependencyCollectionException('dependencies.unsafe_source');
        }

        $arguments = ['/usr/bin/python3', '-I', '-', $instance->environment, $path];
        if ($production) {
            $arguments = ['sudo', '-n', '-u', $user, '-H', '--', ...$arguments];
        }

        try {
            $result = $this->ssh->execute(new SshConnection(
                host: $node->wireguard_ip,
                user: $node->user,
                port: 22,
                identityFile: $this->keys->privateKeyPath(),
                knownHostsFile: $this->knownHosts->path(),
            ), new RemoteCommand(
                arguments: $arguments,
                input: DependencyFilesProgram::render(),
                maxOutputBytes: 48 * 1024 * 1024,
                timeout: 30.0,
            ));
        } catch (Throwable) {
            throw new DependencyCollectionException('dependencies.unreadable_source');
        }

        if (! $result->succeeded() || $result->truncated || $result->stderr !== '') {
            throw new DependencyCollectionException('dependencies.unreadable_source');
        }

        try {
            $receipt = json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new DependencyCollectionException('dependencies.invalid_collection');
        }
        if (! is_array($receipt)) {
            throw new DependencyCollectionException('dependencies.invalid_collection');
        }
        if (isset($receipt['error']) && in_array($receipt['error'], self::ERRORS, true)) {
            throw new DependencyCollectionException($receipt['error']);
        }
        $root = $receipt['root'] ?? null;
        $reference = $receipt['reference'] ?? null;
        $identity = $receipt['identity'] ?? null;
        $files = $receipt['files'] ?? null;
        if (! is_string($root) || ! is_string($identity) || preg_match('/\A[a-f0-9]{64}\z/D', $identity) !== 1
            || ! is_array($files) || array_keys($files) !== DependencyFilesProgram::FILES
            || ($production && (! is_string($reference) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $reference) !== 1 || $root !== $path.'/releases/'.$reference))
            || (! $production && ($root !== $path || $reference !== null))) {
            throw new DependencyCollectionException('dependencies.invalid_collection');
        }
        $contents = $hashes = $errors = [];
        $total = 0;
        foreach ($files as $name => $entry) {
            if (! is_array($entry) || array_keys($entry) !== ['content', 'hash', 'error']) {
                throw new DependencyCollectionException('dependencies.invalid_collection');
            }
            $content = $entry['content'];
            $hash = $entry['hash'];
            $error = $entry['error'];
            if ($error !== null) {
                if (! in_array($error, self::ERRORS, true) || $content !== null || $hash !== null) {
                    throw new DependencyCollectionException('dependencies.invalid_collection');
                }
                $errors[$name] = $error;
            } elseif ($content !== null || $hash !== null) {
                if (! is_string($content) || ! is_string($hash) || ($content = base64_decode($content, true)) === false
                    || ! hash_equals(hash('sha256', $content), $hash)
                    || strlen($content) > (in_array($name, ['composer.json', 'package.json'], true) ? 1024 * 1024 : 8 * 1024 * 1024)) {
                    throw new DependencyCollectionException('dependencies.invalid_collection');
                }
                $total += strlen($content);
            }
            $contents[$name] = $content;
            $hashes[$name] = $hash;
        }
        if ($total > 32 * 1024 * 1024) {
            throw new DependencyCollectionException('dependencies.source_too_large');
        }

        return new CollectedDependencyFiles($root, $reference, $identity, $contents, $hashes, $errors);
    }
}
