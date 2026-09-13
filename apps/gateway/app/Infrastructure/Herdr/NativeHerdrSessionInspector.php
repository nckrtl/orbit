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
        private HerdrObserveContract $contract,
    ) {}

    public function inspect(HerdrSession $session, Node $node): HerdrSessionInspection
    {
        $identity = $this->decode($this->run(
            $node,
            [HerdrObserveContract::Executable, '--session', $session->session, 'identity', '--json'],
        ));
        $panes = $this->decode($this->run(
            $node,
            [HerdrObserveContract::Executable, '--session', $session->session, 'terminal', 'session', 'list', '--json'],
        ));

        return new HerdrSessionInspection(
            version: is_string($identity['version'] ?? null) ? $identity['version'] : null,
            protocol: is_int($identity['protocol'] ?? null) ? $identity['protocol'] : null,
            handoffSupported: ($identity['handoff_supported'] ?? false) === true,
            panes: $this->panes($panes['panes'] ?? []),
        );
    }

    public function handoff(HerdrSession $session, Node $node): void
    {
        $this->run($node, $this->contract->serverCommand($session->session, $session->observer_port, handoff: true));
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
            throw new ResourceOperationException(
                errorCode: 'herdr.inspection_failed',
                message: 'Herdr identity inspection returned invalid JSON.',
                status: 422,
            );
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
            if (! is_array($pane) || ! is_string($pane['pane'] ?? null) || ! is_string($pane['terminal'] ?? null)) {
                continue;
            }

            $items[] = new HerdrLivePane(
                pane: $pane['pane'],
                terminal: $pane['terminal'],
                live: ($pane['live'] ?? false) === true,
            );
        }

        return $items;
    }
}
