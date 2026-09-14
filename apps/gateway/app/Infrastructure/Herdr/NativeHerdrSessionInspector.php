<?php

declare(strict_types=1);

namespace App\Infrastructure\Herdr;

use App\Domain\Herdr\HerdrLivePane;
use App\Domain\Herdr\HerdrObserveContract;
use App\Domain\Herdr\HerdrSessionInspection;
use App\Domain\Herdr\HerdrSessionInspector;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\HerdrSession;
use App\Models\Node;
use JsonException;

final readonly class NativeHerdrSessionInspector implements HerdrSessionInspector
{
    public function __construct(
        private AppDevSshExecutor $ssh,
    ) {}

    public function inspect(HerdrSession $session, Node $node): HerdrSessionInspection
    {
        $response = $this->decode($this->run(
            $node,
            [HerdrObserveContract::Executable, '--session', $session->session, 'api', 'snapshot'],
        ));
        $snapshot = $response['result']['snapshot'] ?? null;

        if (($response['result']['type'] ?? null) !== 'session_snapshot' || ! is_array($snapshot)) {
            throw $this->invalidSnapshot();
        }

        return new HerdrSessionInspection(
            version: is_string($snapshot['version'] ?? null) ? $snapshot['version'] : null,
            protocol: is_int($snapshot['protocol'] ?? null) ? $snapshot['protocol'] : null,
            handoffSupported: false,
            panes: $this->panes($snapshot['panes'] ?? []),
        );
    }

    public function handoff(HerdrSession $session, Node $node): void
    {
        throw new ResourceOperationException(
            errorCode: 'herdr.handoff_unsupported',
            message: 'This Herdr version does not expose a supported server handoff command.',
            status: 422,
        );
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(Node $node, array $arguments): string
    {
        $result = $this->ssh->execute(
            $node,
            new RemoteCommand($arguments),
            step: 'herdr-inspect',
            errorCode: 'herdr.inspection_failed',
        );

        return $result->stdout;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalidSnapshot();
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<HerdrLivePane>
     */
    private function panes(mixed $panes): array
    {
        if (! is_array($panes)) {
            return [];
        }

        $items = [];

        foreach ($panes as $pane) {
            if (! is_array($pane) || ! is_string($pane['pane_id'] ?? null) || ! is_string($pane['terminal_id'] ?? null)) {
                continue;
            }

            $items[] = new HerdrLivePane(
                pane: $pane['pane_id'],
                terminal: $pane['terminal_id'],
                live: true,
            );
        }

        return $items;
    }

    private function invalidSnapshot(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'herdr.inspection_failed',
            message: 'Herdr session inspection returned an invalid snapshot.',
            status: 422,
        );
    }
}
