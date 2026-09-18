<?php

declare(strict_types=1);

use App\Support\Tui\NodeFormState;
use Laravel\Prompts\Key;

describe(NodeFormState::class, function (): void {
    it('rejects an empty name and an out-of-range port the same way node:add would', function (): void {
        $form = new NodeFormState;

        $form->press(Key::ENTER); // Confirm the empty "name" field.
        expect($form->prompts[0][1]->done())->toBeFalse();

        $form->focusField(2); // "port"
        foreach (str_split('99999') as $digit) {
            $form->press($digit);
        }
        $form->press(Key::ENTER);
        expect($form->prompts[2][1]->done())->toBeFalse();
    });

    it('requires at least one role', function (): void {
        $form = new NodeFormState;
        $form->focusField(4); // "roles", pre-selected with app-dev by default.

        expect($form->values()['roles'])->toBe(['app-dev']);
    });

    it('validates every field on submit and stops at the first invalid one', function (): void {
        $form = new NodeFormState;

        expect($form->validate())->toBeFalse()
            ->and($form->active)->toBe(0); // "name" is required and still empty.
    });

    it('submits once every field is valid', function (): void {
        $form = new NodeFormState;

        foreach (str_split('beast') as $letter) {
            $form->press($letter);
        }
        $form->press(Key::ENTER);

        expect($form->active)->toBe(1); // Moved on to "host", which is optional.

        // Accept every remaining field's default or placeholder by confirming it as-is.
        for ($i = 1; $i < count($form->prompts); $i++) {
            $form->press(Key::ENTER);
        }

        expect($form->validate())->toBeTrue()
            ->and($form->values())->toBe([
                'name' => 'beast',
                'host' => '',
                'port' => '22',
                'user' => 'root',
                'roles' => ['app-dev'],
                'tld' => '',
            ]);
    });
});
