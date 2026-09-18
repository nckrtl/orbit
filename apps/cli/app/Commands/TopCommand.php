<?php

declare(strict_types=1);

namespace App\Commands;

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressState;
use App\Support\Realtime\RealtimeState;
use App\Support\Realtime\RealtimeSubscriber;
use App\Support\Realtime\WebSocketTransport;
use App\Support\Tui\ActionRunner;
use App\Support\Tui\Interaction;
use App\Support\Tui\RefreshScheduler;
use App\Support\Tui\Screen;
use App\Support\Tui\Sources\GatewayDatabaseUsersSource;
use App\Support\Tui\Sources\GatewayDeploymentsSource;
use App\Support\Tui\Sources\GatewayNodeMetricsSource;
use App\Support\Tui\State;
use App\Support\Tui\UiState;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Responses\Realtime\RealtimeResponse;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\Event\TerminalResizedEvent;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\Terminal;
use PhpTui\Tui\DisplayBuilder;

/**
 * `orbit top`: the fleet as a live, sectioned screen (Dashboard, Nodes, Apps, Instances,
 * Processes, Schedules, Databases). It is the real command the `design:top` sketch specified
 * (`apps/cli/design/README.md`); the rendering and interaction live in `App\Support\Tui\Screen`
 * and `App\Support\Tui\Interaction`, driven by fleet data in `App\Support\Tui\State`.
 *
 * Startup loads every list once with a progress display, then keeps State current from
 * `App\Support\Realtime\RealtimeSubscriber` when the Gateway's `GET /api/v1/realtime` endpoint
 * (or the profile's own `realtime_url`/`realtime_key`, or `ORBIT_REALTIME_URL`/
 * `ORBIT_REALTIME_KEY`) resolves a socket, and otherwise reloads every `--tick` seconds. The
 * header names the current mode as `live`, `polling`, or `reconnecting`.
 *
 * Record actions (`a` or right-click) run the same SDK request the matching command sends for
 * process start/stop/restart, schedule run/enable, node doctor, database connection destroy,
 * and firewall rule removal. A deploy or rollback streams for minutes and is not run from
 * inside a screen that must keep rendering, so those actions, along with `node:ssh`,
 * `instance:logs`, `instance:profile`, and `database:query` (none of which have a synchronous
 * SDK request), print the equivalent command instead of running it. Deployment history,
 * per-connection database users, and node metrics come from `App\Support\Tui\Sources\
 * GatewayDeploymentsSource`, `GatewayDatabaseUsersSource`, and `GatewayNodeMetricsSource`, kept
 * current by `App\Support\Tui\RefreshScheduler` (see its class doc) rather than by Screen or
 * State fetching on read; when a request fails or times out, its pane says so instead of
 * rendering a table.
 */
final class TopCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'top
        {--tick=10 : Seconds between reloads when realtime is not available}
        {--json : Not supported; orbit top is an interactive screen with no final result}';

    #[\Override]
    protected $description = 'Show the fleet as a live screen.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
        WebSocketTransport $transport,
    ): int {
        if ($this->option('json') === true) {
            return $this->renderGatewayFailure('input.invalid', 'orbit top does not support --json; it is an interactive screen with no final result.');
        }

        if (! $this->consoleMode()->mayPrompt) {
            return $this->renderGatewayFailure('input.invalid', 'orbit top needs an interactive terminal.');
        }

        $tick = filter_var($this->option('tick'), FILTER_VALIDATE_FLOAT);
        $tick = is_float($tick) && $tick >= 1.0 ? $tick : 10.0;

        $profile = $this->activeGatewayProfile($repository);

        if ($profile === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $send = fn (GatewayRequest $request, string $responseClass): object => $this->sendOrThrow($connector, $request, $responseClass);
        /** @param  list<GatewayRequest>  $requests */
        $sendMany = fn (array $requests, string $responseClass): array => $this->poolSend($connector, $requests, $responseClass);

        $state = new State;
        $scheduler = new RefreshScheduler(new GatewayNodeMetricsSource($send), new GatewayDeploymentsSource($send), new GatewayDatabaseUsersSource($send));

        $progress = $this->progressDisplay('Load fleet data');
        $progress->admit('load', 'Load fleet data', 'Loading fleet data', 'Loaded fleet data');

        try {
            $progress->during('load', function () use ($state, $send, $sendMany): true {
                $state->load($send, $sendMany);

                return true;
            });
        } catch (GatewayApiException $exception) {
            $code = $exception->errorCode() ?? 'gateway.request_failed';

            return $this->renderGatewayFailure($code, $exception->getMessage(), $exception->requestId());
        }

        $progress->complete('load', ProgressState::Success);
        $progress->dismiss();

        $subscriber = RealtimeSubscriber::forProfile($this->realtimeProfile($connector, $profile), app()->version(), $transport);
        $subscriber->connect();

        $ui = new UiState;
        $screen = new Screen;
        $interaction = new Interaction($state, $ui, new ActionRunner($send), $send);

        $terminal = Terminal::new();
        $display = DisplayBuilder::default()->fullscreen()->build();
        $terminal->enableRawMode();
        $terminal->execute(Actions::alternateScreenEnable(), Actions::cursorHide(), Actions::enableMouseCapture());

        $lastPoll = microtime(true);

        try {
            while (true) {
                foreach ($subscriber->poll() as $event) {
                    $state->applyEvent($event);
                }

                $state->liveness = match ($subscriber->state()) {
                    RealtimeState::Connected => 'live',
                    RealtimeState::Reconnecting => 'reconnecting',
                    RealtimeState::NotConfigured => 'polling',
                };

                if ($state->liveness !== 'live' && microtime(true) - $lastPoll >= $tick) {
                    $lastPoll = microtime(true);

                    try {
                        $state->load($send);
                    } catch (GatewayApiException $exception) {
                        $ui->message = $exception->getMessage();
                    }
                }

                [$visibleNodeIds, $visibleInstanceIds, $visibleDatabaseSlugs] = $this->visibleRefreshKeys($ui, $state);
                $scheduler->tick($state, $visibleNodeIds, $visibleInstanceIds, $visibleDatabaseSlugs);

                while (($event = $terminal->events()->next()) !== null) {
                    if ($event instanceof CharKeyEvent) {
                        if ($event->char === 'c' && $event->modifiers === KeyModifiers::CONTROL) {
                            break 2;
                        }

                        if ($ui->form === null && $event->char === 'q') {
                            break 2;
                        }

                        $interaction->handleChar($event->char);
                    }

                    if ($event instanceof CodedKeyEvent) {
                        $interaction->handleKey($event->code);
                    }

                    if ($event instanceof MouseEvent) {
                        $interaction->handleMouse($event);
                    }

                    if ($event instanceof TerminalResizedEvent) {
                        $display = DisplayBuilder::default()->fullscreen()->build();
                        $display->clear();
                    }
                }

                $display->draw($screen->screen($state, $ui, $this->header($profile, $state, $lastPoll, $tick), $this->footer($ui), $display->viewportArea()));
                usleep(50_000);
            }
        } finally {
            $terminal->execute(Actions::disableMouseCapture(), Actions::cursorShow(), Actions::alternateScreenDisable());
            $terminal->disableRawMode();
            $subscriber->close();
        }

        return self::SUCCESS;
    }

    /**
     * Asks the Gateway for its realtime endpoint first; when it answers with a configured
     * `url`/`key`, those win. Otherwise RealtimeSubscriber::forProfile() falls back to the
     * profile's own `realtime_url`/`realtime_key` or the `ORBIT_REALTIME_URL`/
     * `ORBIT_REALTIME_KEY` environment override, exactly as `realtime:tail` does. Any transport
     * failure while asking is treated the same as "not configured" rather than failing the
     * whole command.
     */
    private function realtimeProfile(GatewayConnector $connector, GatewayProfile $profile): GatewayProfile
    {
        try {
            $realtime = $this->sendOrThrow($connector, new ShowRealtimeRequest, RealtimeResponse::class);
        } catch (GatewayApiException) {
            return $profile;
        }

        if (! $realtime instanceof RealtimeResponse || $realtime->url === null || $realtime->key === null) {
            return $profile;
        }

        return new GatewayProfile(
            name: $profile->name,
            url: $profile->url,
            caPath: $profile->caPath,
            realtimeUrl: $realtime->url,
            realtimeKey: $realtime->key,
        );
    }

    /**
     * What RefreshScheduler should keep current this frame: every node on the dashboard (or the
     * one node a node page is open on), the one instance an instance page is open on, and the
     * one Database connection a database page is open on. Mirrors what Screen actually draws
     * (only the topmost open page, if any) so the scheduler never fetches for a pane that is not
     * on screen.
     *
     * @return array{list<int>, list<int>, list<string>}
     */
    private function visibleRefreshKeys(UiState $ui, State $state): array
    {
        $page = $ui->page();

        if ($page === null) {
            return $ui->section === 'dashboard' ? [array_column($state->nodes, 'id'), [], []] : [[], [], []];
        }

        return match ($page['kind']) {
            'nodes' => [[$page['row']['id']], [], []],
            'instances' => [[], [$page['row']['id']], []],
            'databases' => [[], [], [$page['row']['slug']]],
            default => [[], [], []],
        };
    }

    private function header(GatewayProfile $profile, State $state, float $lastPoll, float $tick): string
    {
        $age = max(0, (int) round(microtime(true) - $lastPoll));
        $mode = match ($state->liveness) {
            'live' => 'live',
            'reconnecting' => 'reconnecting',
            default => "polling every {$tick}s, refreshed {$age}s ago",
        };

        return "{$profile->url} · {$mode}  ";
    }

    private function footer(UiState $ui): string
    {
        $hint = match (true) {
            $ui->menu !== null && $ui->menu['confirm'] => '  ↑↓ choose · Enter or click confirms · Esc cancels',
            $ui->menu !== null => '  ↑↓ choose · Enter or click runs · Esc closes',
            $ui->form !== null => '  ↑↓ or Tab move between fields · Space toggles a role · Enter confirms a field · Esc cancels',
            $ui->page() !== null && $ui->focus === null => '  ←→ sidebar or page · ↑↓ panes · Enter focuses · Esc or ‹ back · a or right-click actions · q leave',
            $ui->focus === null => '  ↑↓ sections · → into the page · 1-7 jump · '.($ui->section === 'nodes' ? 'c or + create · ' : '').($ui->hasFilters() ? 'n/p filters · ' : '').'q leave',
            default => '  ↑↓ move · Enter or click again opens · a or right-click actions · Esc back to panes · q leave',
        };

        return $hint.($ui->message !== '' ? "  │  {$ui->message}" : '');
    }
}
