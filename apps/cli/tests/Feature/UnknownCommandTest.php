<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @return array{0: int, 1: string}
 */
function run_orbit(string $arguments): array
{
    $output = new BufferedOutput;
    $status = app(Kernel::class)->handle(new StringInput($arguments), $output);

    return [$status, $output->fetch()];
}

describe('unknown command', function (): void {
    it('exits 1 and names the unknown command on one line', function (): void {
        [$status, $output] = run_orbit('status');

        expect($status)
            ->toBe(1)
            ->and(trim($output))
            ->toBe('Command "status" is not defined. Run "orbit list" to see available commands.');
    });

    it('keeps list and help exiting 0', function (string $arguments): void {
        [$status] = run_orbit($arguments);

        expect($status)->toBe(0);
    })->with(['list', '--help', '']);

    it('keeps known commands exiting 0', function (): void {
        [$status] = run_orbit('doctor --help');

        expect($status)->toBe(0);
    });

    it('keeps internal and Laravel-only schedule commands undefined', function (string $command): void {
        [$status, $output] = run_orbit($command);

        expect($status)
            ->toBe(1)
            ->and(trim($output))
            ->toBe(sprintf(
                'Command "%s" is not defined. Run "orbit list" to see available commands.',
                $command,
            ))
            ->not->toContain('scheduled tasks');
    })->with(['schedule:finish', 'schedule:complete']);

    it('treats replaced process, schedule, and Herdr session names as unknown commands', function (string $command): void {
        [$status, $output] = run_orbit($command);

        expect($status)
            ->toBe(1)
            ->and(trim($output))
            ->toBe(sprintf(
                'Command "%s" is not defined. Run "orbit list" to see available commands.',
                $command,
            ));
    })->with([
        'process:add',
        'process:remove',
        'schedule:add',
        'schedule:remove',
        'schedule:activate',
        'herdr:session:add',
        'herdr:session:remove',
    ]);
});
