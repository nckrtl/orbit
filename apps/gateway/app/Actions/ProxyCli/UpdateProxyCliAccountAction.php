<?php

declare(strict_types=1);

namespace App\Actions\ProxyCli;

use App\Domain\ProxyCli\ProxyCliAccountControlClient;
use App\Domain\ProxyCli\ProxyCliPlacement;
use App\Domain\ProxyCli\ProxyCliPoolCompiler;
use App\Domain\ProxyCli\ProxyCliSnapshotStore;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Shared\ResourceOperationException;

final readonly class UpdateProxyCliAccountAction
{
    public function __construct(
        private ProxyCliState $state,
        private ProxyCliSnapshotStore $snapshots,
        private ProxyCliPlacement $placement,
        private ProxyCliAccountControlClient $client,
        private ProxyCliPoolCompiler $compiler = new ProxyCliPoolCompiler,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $account, bool $disabled): array
    {
        $this->state->assertEnabled();
        $node = $this->placement->node($this->state->nodeId() ?? 0);

        $accounts = $this->snapshots->accounts();
        $known = array_any($accounts, static fn ($row): bool => $row->id === $account);

        if (! $known) {
            throw new ResourceOperationException(
                'resource.not_found',
                "Account [{$account}] was not found in the snapshot.",
                404,
            );
        }

        $this->client->setDisabled(
            $node->wireguard_ip,
            443,
            (string) $this->state->controlToken(),
            $account,
            $disabled,
        );
        $updated = $this->compiler->withDisabled($accounts, $account, $disabled);
        $snapshot = $this->snapshots->write($updated, date(DATE_ATOM));
        $match = array_first(array_filter(
            $snapshot->accounts,
            static fn ($row): bool => $row->id === $account,
        )) ?? null;

        return $match?->toArray() ?? ['id' => $account, 'disabled' => $disabled];
    }
}
