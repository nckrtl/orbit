<?php

declare(strict_types=1);

use App\Support\Tui\Confirmation;
use Laravel\Prompts\Key;
use PhpTui\Tui\Display\Backend\DummyBackend;
use PhpTui\Tui\DisplayBuilder;

describe(Confirmation::class, function (): void {
    it('keeps terminal controls inert and renders every wrapped question line', function (): void {
        $confirmation = new Confirmation("Remove [ssh\e[2J\nrule]? The database is not dropped.");
        $backend = DummyBackend::fromDimensions(40, 24);
        $display = DisplayBuilder::default($backend)->fullscreen()->build();
        $display->draw($confirmation->widget($display->viewportArea()));
        $screen = (string) $backend->flushed();

        expect($screen)->toContain('\\u{001B}[2J\\nrule')
            ->not->toContain("\e")
            ->and($confirmation->prompt->confirmed)->toBeFalse();
    });

    it('does not approve before the question has been drawn', function (): void {
        $confirmation = new Confirmation('Remove rule [ssh]?');

        expect($confirmation->press('y'))->toBeNull()
            ->and($confirmation->press(Key::ENTER))->toBeNull()
            ->and($confirmation->click(0, 0))->toBeNull()
            ->and($confirmation->press(Key::ESCAPE))->toBeFalse();
    });
});
