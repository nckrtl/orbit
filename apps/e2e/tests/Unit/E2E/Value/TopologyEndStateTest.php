<?php

declare(strict_types=1);

use App\E2E\Value\TopologyEndState;
use App\E2E\Value\TopologyProfile;

describe('TopologyEndState', function (): void {
    it('says the whole profile stays when a plan declares nothing', function (): void {
        $endState = TopologyEndState::complete();

        expect($endState->nodes)
            ->toBe(TopologyProfile::ROLES)
            ->and($endState->absent())
            ->toBe([])
            ->and($endState->declaresAbsence())
            ->toBeFalse()
            ->and($endState->peers())
            ->toBe(['app-dev', 'app-prod']);
    });

    it('reads a declared set and names what it leaves out', function (): void {
        $endState = TopologyEndState::fromArray(['nodes' => ['gateway', 'app-dev']]);

        expect($endState->nodes)
            ->toBe(['gateway', 'app-dev'])
            ->and($endState->absent())
            ->toBe(['app-prod'])
            ->and($endState->peers())
            ->toBe(['app-dev'])
            ->and($endState->declaresAbsence())
            ->toBeTrue()
            ->and($endState->keeps('app-dev'))
            ->toBeTrue()
            ->and($endState->keeps('app-prod'))
            ->toBeFalse()
            ->and($endState->toArray())
            ->toBe(['nodes' => ['gateway', 'app-dev']]);
    });

    it('records the declared set in profile order however the plan wrote it', function (): void {
        expect(TopologyEndState::fromArray(['nodes' => ['app-prod', 'app-dev', 'gateway']])->nodes)
            ->toBe(TopologyProfile::ROLES)
            ->and(TopologyEndState::fromArray(['nodes' => ['app-dev', 'gateway']])->nodes)
            ->toBe(['gateway', 'app-dev']);
    });

    it('accepts a declaration that keeps the gateway alone', function (): void {
        $endState = TopologyEndState::fromArray(['nodes' => ['gateway']]);

        expect($endState->absent())
            ->toBe(['app-dev', 'app-prod'])
            ->and($endState->peers())
            ->toBe([]);
    });

    it('refuses a declaration that is not an object with exactly the key nodes', function (mixed $declared): void {
        expect(fn (): TopologyEndState => TopologyEndState::fromArray($declared))
            ->toThrow(
                InvalidArgumentException::class,
                'The proof plan key ends_with must be an object with exactly the key nodes.',
            );
    })->with([
        'a list of node names' => [['gateway', 'app-dev']],
        'an extra key' => [['nodes' => ['gateway'], 'roles' => []]],
        'no key at all' => [[]],
        'a string' => ['gateway'],
        'null' => [null],
        'a boolean' => [true],
    ]);

    it('refuses a node list that is not a non-empty list', function (mixed $nodes): void {
        expect(fn (): TopologyEndState => TopologyEndState::fromArray(['nodes' => $nodes]))
            ->toThrow(
                InvalidArgumentException::class,
                'The proof plan key ends_with.nodes must be a non-empty list.',
            );
    })->with([
        'empty' => [[]],
        'an object' => [['0' => 'gateway', 'two' => 'app-dev']],
        'a string' => ['gateway'],
        'null' => [null],
    ]);

    it('refuses a node the profile does not have', function (mixed $node): void {
        expect(fn (): TopologyEndState => TopologyEndState::fromArray(['nodes' => ['gateway', $node]]))
            ->toThrow(
                InvalidArgumentException::class,
                'The proof plan key ends_with.nodes must name nodes from gateway, app-dev, app-prod.',
            );
    })->with([
        'an unknown role' => ['app-staging'],
        'an instance name' => ['orbit-e2e-tst-113-app-prod'],
        'an empty string' => [''],
        'an integer' => [1],
        'null' => [null],
    ]);

    it('refuses the same node twice', function (): void {
        expect(fn (): TopologyEndState => TopologyEndState::fromArray([
            'nodes' => ['gateway', 'app-dev', 'app-dev'],
        ]))
            ->toThrow(InvalidArgumentException::class, 'The proof plan declares node [app-dev] in ends_with twice.');
    });

    it('refuses to let the gateway go', function (array $nodes): void {
        expect(fn (): TopologyEndState => TopologyEndState::fromArray(['nodes' => $nodes]))
            ->toThrow(
                InvalidArgumentException::class,
                'The proof plan key ends_with.nodes must keep the gateway node.',
            );
    })->with([
        'only the app nodes' => [['app-dev', 'app-prod']],
        'only app-dev' => [['app-dev']],
        'only app-prod' => [['app-prod']],
    ]);
});
