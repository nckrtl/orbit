<?php

declare(strict_types=1);

use App\Domain\Logs\LogStream;
use App\Domain\Logs\LogStreamRecordType;
use App\Domain\Logs\LogStreamSource;
use App\Domain\Logs\LogStreamStore;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Node;
use Illuminate\Support\Carbon;

function agent_log_node(string $name, string $address, string $platform = 'linux'): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => $platform,
        'public_ssh_host' => $address,
        'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:'.$name,
    ]);
}

function agent_log_stream(Node $node, LogStreamSource $source, float $expiresAt = 1_060.0, bool $active = true): LogStream
{
    $stream = new LogStream(bin2hex(random_bytes(16)), LogStreamRecordType::Process, 7, (int) $node->id, 99, $source, 250, $expiresAt, $active);
    app(LogStreamStore::class)->open($stream);

    return $stream;
}

describe('the agent stream list', function (): void {
    beforeEach(function (): void {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000));
        $this->node = agent_log_node('app-prod', '10.44.0.11');
        $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    });

    afterEach(fn () => Carbon::setTestNow());

    it('lists only the active open streams whose source is on the calling Node', function (): void {
        $laravel = agent_log_stream($this->node, LogStreamSource::laravel(StoragePath::parse('/home/orbit/apps/shop/main')));
        $journal = agent_log_stream($this->node, LogStreamSource::journal('orbit-process-7-queue.service'));
        $docker = agent_log_stream($this->node, LogStreamSource::docker('orbit-process-8-web', 8));
        agent_log_stream(agent_log_node('app-dev', '10.44.0.12'), LogStreamSource::journal('orbit-process-9-queue.service'));
        agent_log_stream($this->node, LogStreamSource::journal('orbit-process-10-old.service'), expiresAt: 999.0);
        agent_log_stream($this->node, LogStreamSource::journal('orbit-process-11-new.service'), active: false);

        $this->getJson('/api/v1/agent/log-streams')->assertOk()->assertJsonPath('data', [
            ['id' => $laravel->id, 'lines' => 250, 'source' => ['type' => 'laravel', 'path' => '/home/orbit/apps/shop/main']],
            ['id' => $journal->id, 'lines' => 250, 'source' => ['type' => 'journal', 'unit' => 'orbit-process-7-queue.service']],
            ['id' => $docker->id, 'lines' => 250, 'source' => ['type' => 'docker', 'container' => 'orbit-process-8-web', 'process_id' => 8]],
        ]);
    });

    it('refuses a caller that is not an active managed Node', function (): void {
        $this->node->update(['platform' => 'darwin']);
        $this->getJson('/api/v1/agent/log-streams')->assertForbidden()->assertJsonPath('error.code', 'agent.node_ineligible');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
        $this->getJson('/api/v1/agent/log-streams')->assertForbidden()->assertJsonPath('error.code', 'peer.identity_unknown');
    });
});

describe('log stream sources', function (): void {
    it('accept only the three source shapes an agent may read', function (Closure $build): void {
        expect($build)->toThrow(InvalidArgumentException::class);
    })->with([
        'a unit of another service' => [fn () => LogStreamSource::journal('ssh.service')],
        'a unit with a path' => [fn () => LogStreamSource::journal('../orbit-process-1-a.service')],
        'a container of another Process' => [fn () => LogStreamSource::docker('orbit-process-8-web', 9)],
        'another container' => [fn () => LogStreamSource::docker('postgres', 8)],
    ]);

    it('drop a stored stream whose source no longer passes the rules', function (array $source): void {
        expect(LogStreamSource::fromArray($source))->toBeNull();
    })->with([
        'traversal' => [['type' => 'laravel', 'path' => '/home/orbit/../../etc']],
        'relative' => [['type' => 'laravel', 'path' => 'storage/logs']],
        'unknown type' => [['type' => 'file', 'path' => '/etc/shadow']],
        'string process id' => [['type' => 'docker', 'container' => 'orbit-process-8-web', 'process_id' => '8']],
    ]);
});

describe('the agent view', function (): void {
    it('records whether the agent joined its log channel', function (): void {
        $view = app(CacheAgentStateView::class);
        $view->putNode(3, [], 'available', 1, CacheAgentStateView::now(), null, [], true);
        $view->putNode(4, [], 'available', 1, CacheAgentStateView::now(), null);

        expect($view->node(3)->logs)->toBeTrue()->and($view->node(4)->logs)->toBeFalse();
    });
});
