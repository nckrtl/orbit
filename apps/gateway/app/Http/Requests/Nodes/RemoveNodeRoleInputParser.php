<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use App\Domain\Nodes\RoleName;
use App\Http\Requests\TopLevelJsonObjectInspector;
use UnexpectedValueException;

final readonly class RemoveNodeRoleInputParser
{
    public function __construct(
        private TopLevelJsonObjectInspector $jsonInspector,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws UnexpectedValueException
     */
    public function parse(string $json, mixed $routeRole): array
    {
        $input = $this->jsonInspector->inspect($json, ['force', 'purge_data', 'offline']);

        if (is_string($routeRole)) {
            $input['role'] = $routeRole;
        }

        return $input;
    }

    /** @return array<string, mixed> */
    public function safeActivityInput(string $json, mixed $routeRole): array
    {
        try {
            $input = $this->parse($json, $routeRole);
        } catch (UnexpectedValueException) {
            return [];
        }

        if (! $this->isStructurallyValid($input)) {
            return [];
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function isStructurallyValid(array $input): bool
    {
        $role = $input['role'] ?? null;

        if (! is_string($role) || ! RoleName::tryFrom($role) instanceof RoleName) {
            return false;
        }

        return array_all(
            ['force', 'purge_data', 'offline'],
            fn ($key) => ! (array_key_exists($key, $input) && ! is_bool($input[$key])),
        );
    }
}
