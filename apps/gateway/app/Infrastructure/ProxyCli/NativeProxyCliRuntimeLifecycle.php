<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Actions\Processes\AddProcessAction;
use App\Actions\Processes\RemoveProcessAction;
use App\Domain\ProxyCli\ProxyCliProcess;
use App\Domain\ProxyCli\ProxyCliRuntimeLifecycle;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Process;
use RuntimeException;
use SensitiveParameter;

final readonly class NativeProxyCliRuntimeLifecycle implements ProxyCliRuntimeLifecycle
{
    public function __construct(
        private AddProcessAction $add,
        private RemoveProcessAction $remove,
        private ProxyCliSourcePublisher $source,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function converge(
        Node $node,
        #[SensitiveParameter]
        array $environment,
        int $port = ProxyCliProcess::PORT,
    ): void {
        $this->publishSource($node);
        $data = ProxyCliProcess::data($node, $environment, $port);

        try {
            $this->add->execute($data);
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode !== 'process.name_taken') {
                throw $exception;
            }

            $this->remove($node);
            $this->publishSource($node);
            $this->add->execute($data);
        }
    }

    public function remove(Node $node): void
    {
        $process = Process::query()
            ->where('owner_type', Node::class)
            ->where('owner_id', $node->id)
            ->where('name', ProxyCliProcess::NAME)
            ->first();

        if ($process instanceof Process) {
            $this->remove->execute($process, removedByOwningRole: true);
        }

        $address = $node->wireguard_ip;

        if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $this->ssh->execute($this->connection($node, $address), $this->source->removeCommand());
        }
    }

    private function publishSource(Node $node): void
    {
        $address = $node->wireguard_ip;

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException(
                'proxycli.node_invalid',
                "Node [{$node->name}] has no valid WireGuard IPv4 address.",
                422,
            );
        }

        $result = $this->ssh->execute($this->connection($node, $address), $this->source->command($this->script()));

        if (! $result->succeeded()) {
            throw new ResourceOperationException(
                'proxycli.source_publication_failed',
                "Could not install the proxycli collector on node [{$node->name}].",
                422,
            );
        }
    }

    private function script(): string
    {
        $script = file_get_contents(dirname(__DIR__, 3).'/resources/proxycli/server.py');

        if (! is_string($script) || $script === '') {
            throw new RuntimeException('Could not load the proxycli collector.');
        }

        return $script;
    }

    private function connection(Node $node, string $address): SshConnection
    {
        return new SshConnection(
            $address,
            $node->user,
            22,
            $this->keys->privateKeyPath(),
            $this->knownHosts->path(),
        );
    }
}
