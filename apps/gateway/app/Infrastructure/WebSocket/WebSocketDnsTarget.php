<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Models\Node;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Chooses the Node that private DNS names for `reverb.orbit`. A `websocket` move stores the role row on the
 * new Node before that Node serves the site. Until the new Node's build is live, DNS keeps answering with the
 * Node that still holds the site's certificate record, so clients never reach a Node without the site.
 */
final readonly class WebSocketDnsTarget
{
    public const string SettingKey = 'websocket.serving';

    public function __construct(
        private SettingRepository $settings = new SettingRepository,
        private CaddySiteCertificates $certificates = new CaddySiteCertificates,
    ) {}

    /** After the Node's build serves `reverb.orbit`, before private DNS publishes the Node. */
    public function markServing(int $nodeId): void
    {
        $this->settings->put($this->scope($nodeId), self::SettingKey, CarbonImmutable::now('UTC')->toIso8601String());
    }

    /** Before the build that withdraws the site, or when the Node can no longer be reached. */
    public function forget(int $nodeId): void
    {
        $this->settings->delete($this->scope($nodeId), self::SettingKey);
    }

    /**
     * The role's Node once its build serves the site. Before that, another Node that still holds the site's
     * certificate record, which is the old Node of a move. Otherwise the role's Node, as on a first convergence.
     */
    public function node(?Node $roleNode): ?Node
    {
        if (! $roleNode instanceof Node || $this->settings->get($this->scope($roleNode->id), self::SettingKey) !== null) {
            return $roleNode;
        }

        $serving = array_values(array_filter(
            $this->certificates->nodeIds(CaddySiteCertificates::Websocket),
            static fn (int $id): bool => $id !== $roleNode->id,
        ));

        if ($serving === []) {
            return $roleNode;
        }

        return Node::query()
            ->whereIn('id', $serving)
            ->where('status', LifecycleStatus::Active->value)
            ->whereNotNull('wireguard_ip')
            ->orderBy('id')
            ->first() ?? $roleNode;
    }

    /**
     * Every Node whose Reverb serves `reverb.orbit` clients, the DNS target first. During a move that is the
     * new Node once its build is live, and the old Node until its withdrawal ends: the old Node keeps its
     * serving mark until the build that closes its connections, and a Node from before the mark counts
     * while it holds the certificate record. The role's Node counts only once its own build is live.
     *
     * @return list<Node>
     */
    public function servingNodes(?Node $roleNode): array
    {
        $target = $this->node($roleNode);

        if (! $target instanceof Node) {
            return [];
        }

        $roleServes = $roleNode instanceof Node && $this->settings->get($this->scope($roleNode->id), self::SettingKey) !== null;
        $marked = Setting::query()
            ->where('scope_type', SettingScopeType::Node->value)
            ->where('key', self::SettingKey)
            ->whereNotNull('value')
            ->pluck('scope_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $candidates = array_values(array_filter(
            array_unique([...$marked, ...$this->certificates->nodeIds(CaddySiteCertificates::Websocket)]),
            static fn (int $id): bool => $id !== $target->id && ($roleServes || $id !== $roleNode?->id),
        ));

        $others = $candidates === [] ? [] : Node::query()
            ->whereIn('id', $candidates)
            ->where('status', LifecycleStatus::Active->value)
            ->whereNotNull('wireguard_ip')
            ->orderBy('id')
            ->get()
            ->all();

        return [$target, ...$others];
    }

    private function scope(int $nodeId): SettingScope
    {
        return new SettingScope(SettingScopeType::Node, $nodeId);
    }
}
