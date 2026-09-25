<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Infrastructure\Caddy\Build\NodeCaddyListenerResolver;
use App\Infrastructure\Caddy\CaddyFragmentListeners;
use App\Models\Node;

final readonly class RemoteAppDevCaddyManager implements AppDevCaddyManager
{
    public function __construct(
        private AppDevSiteRepository $sites,
        private AppDevCaddyConfigRenderer $renderer,
        private AppDevSshExecutor $ssh,
        private AppDevCaddyPublisher $publisher = new AppDevCaddyPublisher,
        private ?DevelopmentProjectionOperationLock $projection = null,
        private ?NodeCaddyListenerResolver $listeners = null,
    ) {}

    public function converge(Node $node): void
    {
        $this->owner()->run(fn () => $this->convergeSites($node));
    }

    /** The Node's `app-dev.caddy` fragment, rendered from stored state with the Node's listeners. */
    public function render(Node $node): string
    {
        return $this->fragment($node)['configuration'];
    }

    /** @return array{configuration: string, listeners: CaddyFragmentListeners} */
    private function fragment(Node $node): array
    {
        $sites = $this->sites->forNode($node);
        $listeners = ($this->listeners ?? app(NodeCaddyListenerResolver::class))->fragments($node, $sites);

        return [
            'configuration' => $this->renderer->render($sites, $listeners->routeBind()),
            'listeners' => $listeners,
        ];
    }

    private function convergeSites(Node $node): void
    {
        ['configuration' => $configuration, 'listeners' => $listeners] = $this->fragment($node);
        $version = bin2hex(random_bytes(8));

        try {
            $this->ssh->execute(
                $node,
                $this->publisher->command($configuration, $version, $listeners),
                step: 'caddy-config',
                errorCode: 'app-dev.caddy_config_failed',
            );
        } catch (RuntimeConvergenceException $exception) {
            $refusal = CaddyFragmentListeners::refusal($exception->result->stderr ?? '');

            throw $refusal === null ? $exception : new RuntimeConvergenceException(
                step: $exception->step,
                errorCode: $exception->errorCode,
                message: $refusal,
                previous: $exception,
                result: $exception->result,
            );
        }
        $this->ssh->execute(
            $node,
            $this->publisher->serviceOrderingCommand(),
            step: 'caddy-service-ordering',
            errorCode: 'app-dev.caddy_config_failed',
        );
    }

    public function remove(Node $node): void
    {
        $this->owner()->run(fn () => $this->ssh->execute(
            $node,
            $this->publisher->removeCommand(bin2hex(random_bytes(8))),
            step: 'caddy-config',
            errorCode: 'app-dev.caddy_config_failed',
        ));
    }

    private function owner(): DevelopmentProjectionOperationLock
    {
        return $this->projection ?? app(DevelopmentProjectionOperationLock::class);
    }
}
