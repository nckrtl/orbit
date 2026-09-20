<?php

declare(strict_types=1);

namespace App\Actions\ProxyCli;

use App\Domain\ProxyCli\ProxyCliManagementClient;
use App\Domain\ProxyCli\ProxyCliPoolCompiler;
use App\Domain\ProxyCli\ProxyCliSnapshotStore;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Shared\ResourceOperationException;

final readonly class UpdateProxyCliAccountAction
{
    public function __construct(
        private ProxyCliState $state,
        private ProxyCliSnapshotStore $snapshots,
        private ProxyCliManagementClient $client,
        private ProxyCliPoolCompiler $compiler = new ProxyCliPoolCompiler,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $account, bool $disabled): array
    {
        $this->state->assertEnabled();
        $url = $this->state->cliproxyUrl();
        $key = $this->state->cliproxyManagementKey();

        if (! is_string($url) || ! is_string($key)) {
            throw new ResourceOperationException(
                'proxycli.disabled',
                'The proxycli extension has no stored CLIProxyAPI credentials.',
                409,
            );
        }

        $accounts = $this->snapshots->accounts();
        $known = array_any($accounts, static fn ($row): bool => $row->id === $account);

        if (! $known) {
            throw new ResourceOperationException(
                'resource.not_found',
                "Account [{$account}] was not found in the snapshot.",
                404,
            );
        }

        $this->client->setDisabled($url, $key, $account, $disabled);
        $updated = $this->compiler->withDisabled($accounts, $account, $disabled);
        $snapshot = $this->snapshots->write($updated, date(DATE_ATOM));
        $match = array_values(array_filter(
            $snapshot->accounts,
            static fn ($row): bool => $row->id === $account,
        ))[0] ?? null;

        return $match?->toArray() ?? ['id' => $account, 'disabled' => $disabled];
    }
}
