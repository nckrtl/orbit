<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('rejects each retired workspace command as unknown without sending a request', function (string $command): void {
    $name = explode(' ', $command, 2)[0];
    $output = new BufferedOutput;
    $status = app(Kernel::class)->handle(new StringInput($command), $output);

    expect(collect(app(Kernel::class)->all())->keys()->all())
        ->not->toContain($name)
        ->and($status)
        ->toBe(Command::FAILURE)
        ->and(trim($output->fetch()))
        ->toContain(sprintf('Command "%s" is not defined.', $name));
})->with([
    'workspace:list',
    'workspace:new 1 feature-auth',
    'workspace:php 7 8.5',
    'workspace:remove 7',
    'workspace:show 7',
]);
