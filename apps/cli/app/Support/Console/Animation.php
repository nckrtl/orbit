<?php

declare(strict_types=1);

namespace App\Support\Console;

use Closure;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Owns presentation only. The application callback always runs in this process. */
final class Animation
{
    private static ?self $active = null;

    private static bool $shutdownRegistered = false;

    /** @var resource|null */
    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private bool $plainShown = false;

    private ?self $parent = null;

    private float $epoch = 0;

    /** @var array{string, string}|null */
    private ?array $rendererFrames = null;

    private string $displayedFrame = '';

    private string $settledFrame = '';

    /** @var array<int, TerminalRegion> */
    private array $children = [];

    /**
     * @param  array{string, string}  $frames
     * @param  (Closure(): string)|null  $settled
     */
    public function __construct(
        private readonly ConsoleMode $mode,
        private readonly OutputInterface $output,
        private readonly array $frames,
        private readonly ?string $plain = null,
        private readonly ?TerminalRegion $region = null,
        private readonly ?Closure $settled = null,
    ) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function during(Closure $operation): mixed
    {
        $previous = self::$active;
        $this->parent = $previous?->output === $this->output ? $previous : null;
        $this->epoch = $this->parent->epoch ?? 0;

        if ($this->parent === null) {
            $previous?->stop(clear: true);
        }

        if ($this->parent !== null) {
            $this->displayedFrame = $this->parent->displayedFrame;

            if ($this->region !== null) {
                unset($this->parent->children[spl_object_id($this->region)]);
                $this->region->detach();
            }
        }

        self::$active = $this;
        $handlers = [];
        $async = null;
        $failure = null;

        if (! self::$shutdownRegistered) {
            register_shutdown_function(static function (): void {
                self::$active?->stop(checkStatus: false);
            });
            self::$shutdownRegistered = true;
        }

        try {
            if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal_get_handler')) {
                $async = pcntl_async_signals();

                foreach ([SIGINT, SIGTERM] as $signal) {
                    $handlers[$signal] = pcntl_signal_get_handler($signal);
                    pcntl_signal($signal, static function (int $received): never {
                        throw new ConsoleInterrupted($received);
                    });
                }

                pcntl_async_signals(true);
            }

            $this->start();
            $result = $operation();
            $this->stop(handoff: $this->parent !== null);

            return $result;
        } catch (Throwable $exception) {
            $failure = $exception;

            throw $exception;
        } finally {
            $cleanupFailure = null;

            try {
                $this->stop(checkStatus: false, handoff: $this->parent !== null);
            } catch (Throwable $exception) {
                $cleanupFailure = $exception;
            }

            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }

            if ($async !== null) {
                pcntl_async_signals($async);
            }

            self::$active = $previous;

            if ($this->parent !== null) {
                $this->parent->region?->record($this->displayedFrame);

                if ($this->region !== null && $this->settledFrame !== '') {
                    $this->parent->children[spl_object_id($this->region)] = $this->region;
                    $this->region->attach($this->parent, $this->settledFrame);
                }
            }

            try {
                $previous?->start();
            } catch (Throwable $exception) {
                $cleanupFailure ??= $exception;
            }

            if ($failure === null && $cleanupFailure !== null) {
                throw $cleanupFailure;
            }
        }
    }

    public function __destruct()
    {
        $this->stop(checkStatus: false);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    public static function withoutRepainting(Closure $write): mixed
    {
        $active = self::$active;

        if ($active === null || ! is_resource($active->process)) {
            return $write();
        }

        $active->stop(clear: true);
        $failure = null;

        try {
            return $write();
        } catch (Throwable $exception) {
            $failure = $exception;

            throw $exception;
        } finally {
            try {
                $active->start();
            } catch (Throwable $exception) {
                if ($failure === null) {
                    throw $exception;
                }
            }
        }
    }

    public static function renderRegion(TerminalRegion $region, OutputInterface $output, string $frame): bool
    {
        $active = self::$active;

        if ($active === null || $active->output !== $output || $active->region === $region || ! is_resource($active->process)) {
            return false;
        }

        $active->children[spl_object_id($region)] = $region;
        $region->attach($active, $frame);
        $active->start();

        return true;
    }

    public function active(): bool
    {
        return self::$active === $this;
    }

    private function start(): void
    {
        $stream = ConsoleMode::outputStream($this->output);

        if ($this->mode->machine || $this->output->isQuiet()) {
            return;
        }

        if (! $this->mode->mayRepaint || ! is_resource($stream) || ! function_exists('proc_open')) {
            if (! $this->plainShown) {
                ConsoleWriter::write($this->output, $this->plain ?? TerminalText::plain($this->frames[0]));
                $this->plainShown = true;
            }

            return;
        }

        $frames = $this->presentationFrames();
        $clear = $this->region?->clearSequence() ?: ($this->parent?->region?->clearSequence() ?? '');
        $payload = json_encode(['frames' => $frames, 'clear' => $clear, 'epoch' => $this->epoch], JSON_THROW_ON_ERROR)."\n";

        if ($this->parent !== null && is_resource($this->parent->process)) {
            $this->takeRenderer($this->parent);
        }

        if (is_resource($this->process)) {
            if ($frames !== $this->rendererFrames) {
                $this->send($payload);
                $this->awaitPaint();
                $this->rendererFrames = $frames;
            }

            $this->displayedFrame = $frames[0];
            $this->region?->record($this->displayedFrame);

            return;
        }

        $process = @proc_open(
            [PHP_BINARY, __DIR__.'/Renderers/animate.php'],
            [0 => ['pipe', 'r'], 1 => $stream, 2 => ['pipe', 'w'], 3 => ['pipe', 'w']],
            $pipes,
            options: ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start terminal renderer.');
        }

        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_blocking($this->pipes[0], false);
        $this->send($payload);
        $this->awaitPaint();
        $this->rendererFrames = $frames;
        $this->displayedFrame = $frames[0];
        $this->region?->record($this->displayedFrame);
    }

    private function awaitPaint(): void
    {
        $read = [$this->pipes[3]];
        $write = $except = [];

        if (@stream_select($read, $write, $except, 2) !== 1) {
            throw new RuntimeException('Terminal renderer did not become ready.');
        }

        $ready = fgets($this->pipes[3]);
        $receipt = is_string($ready) ? json_decode($ready, true, flags: JSON_THROW_ON_ERROR) : null;

        if (! is_array($receipt) || ($receipt['ready'] ?? false) !== true || ! is_numeric($receipt['epoch'] ?? null)) {
            throw new RuntimeException('Terminal renderer did not become ready.');
        }

        $this->epoch = (float) $receipt['epoch'];
    }

    private function takeRenderer(self $owner): void
    {
        $this->process = $owner->process;
        $this->pipes = $owner->pipes;
        $this->epoch = $owner->epoch;
        $this->rendererFrames = $owner->rendererFrames;
        $owner->process = null;
        $owner->pipes = [];
        $owner->rendererFrames = null;
    }

    /** @return array{string, string} */
    private function presentationFrames(): array
    {
        $prefix = $this->parent?->presentationFrames() ?? ['', ''];
        $children = $this->childrenFrame();

        return [$prefix[0].$this->frames[0].$children, $prefix[1].$this->frames[1].$children];
    }

    private function childrenFrame(): string
    {
        return implode('', array_map(static fn (TerminalRegion $region): string => $region->content(), $this->children));
    }

    private function send(string $message): void
    {
        $deadline = microtime(true) + 2;

        while ($message !== '') {
            $read = $except = [];
            $write = [$this->pipes[0]];

            $selected = @stream_select($read, $write, $except, 0, 100000);

            if ($selected === false || microtime(true) >= $deadline) {
                throw new RuntimeException('Terminal renderer input did not respond.');
            }

            if ($selected === 0) {
                continue;
            }

            $written = @fwrite($this->pipes[0], substr($message, 0, 8192));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Terminal renderer input closed unexpectedly.');
            }

            $message = substr($message, $written);
        }

        @fflush($this->pipes[0]);
    }

    private function stop(bool $checkStatus = true, bool $clear = false, bool $handoff = false): void
    {
        if (! is_resource($this->process)) {
            return;
        }

        $process = $this->process;
        $exitCode = 1;
        $settled = '';
        $finalFrames = ['', ''];
        $presentationFailed = false;
        $previousMask = [];
        $masked = function_exists('pcntl_sigprocmask') && pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM], $previousMask);

        try {
            try {
                $settled = ! $clear && $this->settled !== null ? ($this->settled)() : '';
                $prefix = ! $clear ? ($this->parent?->presentationFrames() ?? ['', '']) : ['', ''];
                $children = ! $clear ? $this->childrenFrame() : '';
                $finalFrames = [$prefix[0].$settled.$children, $prefix[1].($settled === $this->frames[0] ? $this->frames[1] : $settled).$children];

                if ($handoff && $this->parent !== null) {
                    $this->send(json_encode(['frames' => $finalFrames], JSON_THROW_ON_ERROR)."\n");
                    $this->awaitPaint();
                    $this->rendererFrames = $finalFrames;
                    $this->displayedFrame = $finalFrames[0];
                    $this->settledFrame = $settled === '' ? '' : $settled.$this->childrenFrame();
                    $this->region?->record($this->displayedFrame);
                    $this->parent->takeRenderer($this);

                    return;
                }

                $this->send(json_encode(['final' => $finalFrames], JSON_THROW_ON_ERROR)."\n");
            } catch (Throwable) {
                $presentationFailed = true;
                $settled = '';
                $finalFrames = ['', ''];
            }

            fclose($this->pipes[0]);
            unset($this->pipes[0]);
            $deadline = microtime(true) + 2;

            do {
                $status = proc_get_status($process);

                if (! $status['running']) {
                    break;
                }

                usleep(10000);
            } while (microtime(true) < $deadline);

            if ($status['running']) {
                proc_terminate($process);
                usleep(10000);
                $status = proc_get_status($process);

                if ($status['running']) {
                    proc_terminate($process, 9);
                }
            }
        } finally {
            if (is_resource($this->process)) {
                foreach ($this->pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }

                $this->pipes = [];
                $status ??= proc_get_status($process);

                if ($status['running']) {
                    proc_terminate($process, 9);
                }

                $exitCode = proc_close($process);
                $this->process = null;
                $this->rendererFrames = null;
                $exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : $exitCode;

                if ($exitCode !== 0 || $presentationFailed) {
                    $stream = ConsoleMode::outputStream($this->output);

                    if (is_resource($stream)) {
                        @fwrite($stream, ($this->region?->clearSequence() ?? '')."\e[0m\e[?25h");
                    }

                    $settled = '';
                    $finalFrames = ['', ''];
                }

                $this->displayedFrame = $finalFrames[0];
                $this->settledFrame = $settled === '' ? '' : $settled.$this->childrenFrame();
                $this->region?->record($this->displayedFrame);
            }

            if ($masked) {
                pcntl_sigprocmask(SIG_SETMASK, $previousMask);
            }
        }

        if ($checkStatus && ($exitCode !== 0 || $presentationFailed)) {
            throw new RuntimeException('Terminal renderer stopped unexpectedly.');
        }
    }
}
