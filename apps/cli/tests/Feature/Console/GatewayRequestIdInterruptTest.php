<?php

declare(strict_types=1);

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\InterruptIntent;
use App\Support\Console\PromptAborted;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Support\GatewayRequestId;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInputException;
use Symfony\Component\Console\Tester\CommandTester;
use UnexpectedValueException;

beforeEach(function (): void {
    InterruptIntent::clear();
});

afterEach(function (): void {
    InterruptIntent::clear();
});

it('keeps SIGINT and SIGTERM as cancellation when the request-ID resolver wraps the throw', function (int $signal): void {
    if (! defined('SIGINT') || ! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('pcntl signals are required.');
    }

    $async = pcntl_async_signals();
    $original = pcntl_signal_get_handler($signal);
    pcntl_signal($signal, static function (int $received): never {
        throw new ConsoleInterrupted($received);
    }, restart_syscalls: false);
    pcntl_async_signals(true);

    try {
        $connector = new GatewayConnector(
            baseUrl: 'https://10.70.0.1',
            requestIdResolver: function () use ($signal): string {
                posix_kill((int) getmypid(), $signal);
                pcntl_signal_dispatch();

                return GatewayRequestId::generate();
            },
        );
        $connector->withMockClient(new MockClient([
            ListNodesRequest::class => MockResponse::make(['data' => []]),
        ]));

        expect(fn () => $connector->send(new ListNodesRequest))
            ->toThrow(UnexpectedValueException::class, 'Gateway request ID resolver failed.');
        expect(InterruptIntent::pending())->toBe($signal);
        $connector->getMockClient()?->assertNothingSent();
    } finally {
        pcntl_signal($signal, $original);
        pcntl_async_signals($async);
    }
})->with([SIGINT, SIGTERM]);

it('returns 128 plus the signal when a command swallows ConsoleInterrupted and returns success', function (int $signal): void {
    $command = new class($signal) extends GatewayCommand
    {
        #[Override]
        protected $signature = 'test:swallowed-interrupt';

        #[Override]
        protected $description = 'Fixture';

        public function __construct(private readonly int $signal)
        {
            parent::__construct();
        }

        public function handle(): int
        {
            try {
                throw new ConsoleInterrupted($this->signal);
            } catch (ConsoleInterrupted) {
                return self::SUCCESS;
            }
        }
    };
    $command->setLaravel(app());

    expect(new CommandTester($command)->execute([]))->toBe(128 + $signal)
        ->and(InterruptIntent::pending())->toBeNull();
})->with([SIGINT, SIGTERM]);

it('gives pending cancellation precedence over PromptAborted and console input exceptions', function (): void {
    $aborted = new class extends GatewayCommand
    {
        #[Override]
        protected $signature = 'test:aborted-interrupt';

        #[Override]
        protected $description = 'Fixture';

        public function handle(): int
        {
            InterruptIntent::record(SIGINT);

            throw new PromptAborted;
        }
    };
    $invalid = new class extends GatewayCommand
    {
        #[Override]
        protected $signature = 'test:input-interrupt';

        #[Override]
        protected $description = 'Fixture';

        public function handle(): int
        {
            InterruptIntent::record(SIGTERM);

            throw new RuntimeException('not used');
        }
    };
    $consoleInput = new class extends GatewayCommand
    {
        #[Override]
        protected $signature = 'test:console-input-interrupt';

        #[Override]
        protected $description = 'Fixture';

        public function handle(): int
        {
            InterruptIntent::record(SIGINT);

            throw new ConsoleInputException('Command input is invalid.');
        }
    };
    foreach ([[$aborted, 130], [$invalid, 143], [$consoleInput, 130]] as [$command, $status]) {
        $command->setLaravel(app());
        expect(new CommandTester($command)->execute([]))->toBe($status);
    }
    expect(InterruptIntent::pending())->toBeNull();
});

it('restores parent intent after a nested command and keeps sequential commands independent', function (): void {
    $nested = new class extends GatewayCommand
    {
        #[Override]
        protected $signature = 'test:nested-interrupt';

        #[Override]
        protected $description = 'Fixture';

        public function handle(): int
        {
            InterruptIntent::record(SIGTERM);

            throw new UnexpectedValueException('Gateway request ID resolver failed.');
        }
    };
    $parent = new class($nested) extends GatewayCommand
    {
        #[Override]
        protected $signature = 'test:parent-interrupt';

        #[Override]
        protected $description = 'Fixture';

        public function __construct(private readonly GatewayCommand $nested)
        {
            parent::__construct();
        }

        public function handle(): int
        {
            InterruptIntent::record(SIGINT);
            $nestedStatus = $this->nested->run($this->input, $this->output);
            expect($nestedStatus)->toBe(143)
                ->and(InterruptIntent::pending())->toBe(SIGINT);

            return self::SUCCESS;
        }
    };
    $nested->setLaravel(app());
    $parent->setLaravel(app());

    expect(new CommandTester($parent)->execute([]))->toBe(130)
        ->and(InterruptIntent::pending())->toBeNull();

    $sequential = new class extends GatewayCommand
    {
        #[Override]
        protected $signature = 'test:sequential-ok';

        #[Override]
        protected $description = 'Fixture';

        public function handle(): int
        {
            expect(InterruptIntent::pending())->toBeNull();

            return self::SUCCESS;
        }
    };
    $sequential->setLaravel(app());
    expect(new CommandTester($sequential)->execute([]))->toBe(0);
});

it('returns 128 plus the signal when GatewayCommand sees a wrapped resolver failure after SIGINT', function (int $signal): void {
    $command = new class($signal) extends GatewayCommand
    {
        #[Override]
        protected $signature = 'test:wrapped-interrupt';

        #[Override]
        protected $description = 'Fixture';

        public function __construct(private readonly int $signal)
        {
            parent::__construct();
        }

        public function handle(): int
        {
            InterruptIntent::record($this->signal);

            throw new UnexpectedValueException('Gateway request ID resolver failed.');
        }
    };
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    expect($tester->execute([]))->toBe(128 + $signal)
        ->and($tester->getDisplay())->not->toContain('Gateway request ID resolver failed.')
        ->and(InterruptIntent::pending())->toBeNull();
})->with([SIGINT, SIGTERM]);
