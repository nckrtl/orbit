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
use App\Support\Tui\Sources\GrafanaPrometheusMetricsSource;
use App\Support\Tui\State;
use App\Support\Tui\UiState;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayRequest;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\Event\TerminalResizedEvent;
use PhpTui\Term\EventProvider\AggregateEventProvider;
use PhpTui\Term\EventProvider\SignalEventProvider;
use PhpTui\Term\EventProvider\SyncTtyEventProvider;
use PhpTui\Term\InformationProvider\SizeFromSttyProvider;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;

/**
 * `orbit top`: the fleet as a live, sectioned screen (Dashboard, Nodes, Projects, Instances,
 * Processes, Schedules, Databases, Firewall). It is the real command the `design:top` sketch specified
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
 * GatewayDeploymentsSource`, `GatewayDatabaseUsersSource`, and `GrafanaPrometheusMetricsSource`, kept
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
        $sendMany = fn (array $requests, string $responseClass): array => $this->poolSend($connector, $requests, $responseClass, concurrency: 16);

        $state = new State;
        $metricsSource = new GrafanaPrometheusMetricsSource($send, $profile->caPath);
        $scheduler = new RefreshScheduler($metricsSource, $metricsSource, new GatewayDeploymentsSource($send), new GatewayDatabaseUsersSource($send));

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

        $state->queueProcesses();

        $progress->complete('load', ProgressState::Success);
        $progress->dismiss();

        $subscriber = RealtimeSubscriber::forProfile($this->discoveredRealtimeProfile($connector, $profile), app()->version(), $transport);
        $subscriber->connect();

        $ui = new UiState;
        $screen = new Screen;
        $interaction = new Interaction($state, $ui, new ActionRunner($send), $send);

        $terminal = Terminal::new(
            infoProvider: SizeFromSttyProvider::new(),
            eventProvider: new AggregateEventProvider([SignalEventProvider::registered(), SyncTtyEventProvider::new()]),
        );
        $display = DisplayBuilder::default(PhpTermBackend::new($terminal))->fullscreen()->build();
        $terminal->enableRawMode();
        $terminal->execute(Actions::alternateScreenEnable(), Actions::cursorHide(), Actions::enableMouseCapture());

        $lastPoll = microtime(true);
        $drawnFrames = 0;

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
                        $state->load($send, $sendMany);
                        $state->queueProcesses();
                    } catch (GatewayApiException $exception) {
                        $ui->message = $exception->getMessage();
                    }
                }

                $handledInput = false;

                while (($event = $terminal->events()->next()) !== null) {
                    $handledInput = true;

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
                        ($ui->menu['confirm'] ?? null)?->invalidate();
                        $display = DisplayBuilder::default(PhpTermBackend::new($terminal))->fullscreen()->build();
                        $display->clear();
                    }

                    // Publish this input's selection and geometry before admitting the next event.
                    break;
                }

                $display->draw($screen->screen($state, $ui, $this->header($profile, $state, $lastPoll, $tick), $this->footer($ui), $display->viewportArea()));

                $drawnFrames++;

                // Fetching only on an idle frame is what keeps the screen responsive. Measured
                // against a real fleet, one batch of Process status costs 1.7s on average and up
                // to 3.5s, because the Gateway reads each Process's live state over SSH, and the
                // metrics fetch allows itself three seconds. A frame that handled a key press
                // therefore fetches nothing: it draws and comes straight back for the next key,
                // so navigation runs at the speed of the screen no matter what is still loading.
                if ($handledInput) {
                    continue;
                }

                [$visibleNodeIds, $dashboardVisible, $visibleInstanceIds, $visibleDatabaseSlugs] = $this->visibleRefreshKeys($ui, $state);
                $scheduler->tick($state, $visibleNodeIds, $dashboardVisible, $visibleInstanceIds, $visibleDatabaseSlugs);

                if (! $state->processesLoaded) {
                    try {
                        $state->loadNextProcesses($send, $sendMany);
                    } catch (GatewayApiException $exception) {
                        $state->processesLoaded = true;
                        $ui->message = $exception->getMessage();
                    }
                }

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
     * What RefreshScheduler should keep current this frame: every node on the dashboard (or the
     * one node a node page is open on), the one instance an instance page is open on, and the
     * one Database connection a database page is open on. Mirrors what Screen actually draws
     * (only the topmost open page, if any) so the scheduler never fetches for a pane that is not
     * on screen. The second element tells RefreshScheduler whether the node ids are "every node
     * on the dashboard" (fetched with one fleet-wide request) or "the one node a node page is
     * open on" (fetched on its own).
     *
     * @return array{list<int>, bool, list<int>, list<string>}
     */
    private function visibleRefreshKeys(UiState $ui, State $state): array
    {
        $page = $ui->page();

        if ($page === null) {
            return $ui->section === 'dashboard' ? [array_column($state->nodes, 'id'), true, [], []] : [[], false, [], []];
        }

        return match ($page['kind']) {
            'nodes' => [[$page['row']['id']], false, [], []],
            'instances' => [[], false, [$page['row']['id']], []],
            'databases' => [[], false, [], [$page['row']['slug']]],
            default => [[], false, [], []],
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
            $ui->menu !== null && $ui->menu['confirm'] !== null => '  ←→ or y/n choose · Enter accepts · Left-click No/Yes · Esc cancels',
            $ui->menu !== null => '  ↑↓ choose · Enter or click runs · Esc closes',
            $ui->form !== null => '  ↑↓ or Tab move between fields · Space toggles a role · Enter confirms a field · Esc cancels',
            isset($ui->drawn[$ui->focus ?? '']['textLines']) => '  ↑↓ or wheel scroll · Esc back to panes · q leave',
            $ui->page() !== null && $ui->focus === null => '  ←→ sidebar or page · ↑↓ panes · Enter focuses · Esc or ‹ back · a or right-click actions · q leave',
            $ui->focus === null => '  ↑↓ sections · → into the page · 1-8 jump · '.($ui->section === 'nodes' ? 'c or + create · ' : '').($ui->hasFilters() ? 'n/p filters · ' : '').'q leave',
            default => '  ↑↓ move · Enter or click again opens · a or right-click actions · Esc back to panes · q leave',
        };

        return $hint.($ui->message !== '' ? "  │  {$ui->message}" : '');
    }
}
