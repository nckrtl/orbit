<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * The declared input of one proof: ordered setup and acceptance actions the
 * runner executes as exact argument vectors, plus the post-deployment actions a
 * reviewer approved for the release.
 *
 * The plan is validated once at construction and never carries stdin: a proof
 * must not hold secrets, and the record stores the plan verbatim.
 */
/** @mago-expect lint:cyclomatic-complexity,kan-defect Every plan rule is checked fail-closed in one place. */
final readonly class ProofPlan
{
    public const int MAX_TIMEOUT_SECONDS = 900;

    public const int MAX_ID_LENGTH = 64;

    private const array SECTIONS = ['setup', 'acceptance', 'post_deployment_actions'];

    private const array ACTION_KEYS = ['id', 'node', 'argv', 'timeout_seconds'];

    private const array POST_DEPLOYMENT_KEYS = ['target', 'operation', 'reason', 'recovery', 'verification'];

    /**
     * @param list<array{id:string,node:string,argv:list<string>,timeout_seconds:int}> $setup
     * @param list<array{id:string,node:string,argv:list<string>,timeout_seconds:int}> $acceptance
     * @param list<array{target:string,operation:string,reason:string,recovery:string,verification:string}> $postDeploymentActions
     */
    private function __construct(
        public array $setup,
        public array $acceptance,
        public array $postDeploymentActions,
    ) {}

    public static function fromFile(string $path): self
    {
        if ($path === '' || str_contains($path, "\0") || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('The proof plan file cannot be read.');
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new InvalidArgumentException('The proof plan file cannot be read.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, associative: false, depth: 16, flags: JSON_THROW_ON_ERROR);
            if (! $decoded instanceof stdClass) {
                throw new InvalidArgumentException('The proof plan must be a JSON object.');
            }
            // An object with index-like keys decodes to a PHP list; only the raw decode tells them apart.
            foreach (self::SECTIONS as $section) {
                if (property_exists($decoded, $section) && ! is_array($decoded->{$section})) {
                    throw new InvalidArgumentException("The proof plan section [{$section}] must be a list.");
                }
            }
            /** @var array<array-key, mixed> $plan */
            $plan = json_decode($content, associative: true, depth: 16, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The proof plan must be a JSON object.');
        }

        return self::fromArray($plan);
    }

    /** @param array<array-key, mixed> $plan */
    public static function fromArray(array $plan): self
    {
        $keys = array_keys($plan);
        sort($keys, SORT_STRING);
        $expected = self::SECTIONS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new InvalidArgumentException(
                'The proof plan must have exactly the keys setup, acceptance, and post_deployment_actions.',
            );
        }
        $sections = [];
        foreach (self::SECTIONS as $section) {
            /** @var mixed $declared */
            $declared = $plan[$section];
            if (! is_array($declared) || ! array_is_list($declared)) {
                throw new InvalidArgumentException("The proof plan section [{$section}] must be a list.");
            }
            $sections[$section] = $declared;
        }
        if ($sections['acceptance'] === []) {
            throw new InvalidArgumentException('The proof plan must declare at least one acceptance action.');
        }

        $ids = [];
        $setup = self::actions('setup', $sections['setup'], $ids);
        $acceptance = self::actions('acceptance', $sections['acceptance'], $ids);
        $postDeploymentActions = [];
        /** @mago-expect analysis:mixed-assignment Each declared action is validated one field at a time. */
        foreach ($sections['post_deployment_actions'] as $index => $action) {
            $postDeploymentActions[] = self::postDeploymentAction($index, $action);
        }

        return new self($setup, $acceptance, $postDeploymentActions);
    }

    /**
     * @param list<mixed> $declared
     * @param array<string, true> $ids
     * @return list<array{id:string,node:string,argv:list<string>,timeout_seconds:int}>
     */
    private static function actions(string $section, array $declared, array &$ids): array
    {
        $actions = [];
        /** @mago-expect analysis:mixed-assignment Each declared action is validated one field at a time. */
        foreach ($declared as $index => $action) {
            $label = "{$section}#{$index}";
            if (is_array($action) && array_key_exists('stdin', $action)) {
                throw new InvalidArgumentException(
                    "Proof action [{$label}] cannot carry stdin; the plan must not hold secrets.",
                );
            }
            if (! is_array($action) || ! self::hasExactKeys($action, self::ACTION_KEYS)) {
                throw new InvalidArgumentException(
                    "Proof action [{$label}] must have exactly the keys id, node, argv, and timeout_seconds.",
                );
            }
            /** @var mixed $id */
            $id = $action['id'];
            if (
                ! is_string($id)
                || strlen($id) > self::MAX_ID_LENGTH
                || preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $id) !== 1
            ) {
                throw new InvalidArgumentException(
                    "Proof action [{$label}] must have an ID of 1 through "
                    .self::MAX_ID_LENGTH
                    .' lowercase letters, digits, and hyphens.',
                );
            }
            if (array_key_exists($id, $ids)) {
                throw new InvalidArgumentException("Proof action ID [{$id}] is declared more than once.");
            }
            $ids[$id] = true;
            /** @var mixed $node */
            $node = $action['node'];
            if (! is_string($node) || ! in_array($node, TopologyProfile::ROLES, strict: true)) {
                throw new InvalidArgumentException(
                    "Proof action [{$id}] must name a node from ".implode(', ', TopologyProfile::ROLES).'.',
                );
            }
            /** @var mixed $argv */
            $argv = $action['argv'];
            if (! is_array($argv) || $argv === [] || ! array_is_list($argv)) {
                throw new InvalidArgumentException("Proof action [{$id}] must have a non-empty argument vector.");
            }
            if (! is_string($argv[0]) || ! GuestCommand::isProgramArgument($argv[0])) {
                throw new InvalidArgumentException(
                    "Proof action [{$id}] must start with a program; the first argument cannot carry `=` or start with `-`.",
                );
            }
            $arguments = [];
            /** @mago-expect analysis:mixed-assignment Each argument is validated before it joins the vector. */
            foreach ($argv as $argument) {
                if (! is_string($argument) || preg_match('/[\0\r\n]/', $argument) === 1) {
                    throw new InvalidArgumentException(
                        "Proof action [{$id}] has an argument that is not one string free of NUL bytes and newlines.",
                    );
                }
                $arguments[] = $argument;
            }
            /** @var mixed $timeout */
            $timeout = $action['timeout_seconds'];
            if (! is_int($timeout) || $timeout < 1 || $timeout > self::MAX_TIMEOUT_SECONDS) {
                throw new InvalidArgumentException(
                    "Proof action [{$id}] must have a timeout from 1 through ".self::MAX_TIMEOUT_SECONDS.' seconds.',
                );
            }
            $actions[] = ['id' => $id, 'node' => $node, 'argv' => $arguments, 'timeout_seconds' => $timeout];
        }

        return $actions;
    }

    /** @return array{target:string,operation:string,reason:string,recovery:string,verification:string} */
    private static function postDeploymentAction(int $index, mixed $action): array
    {
        if (! is_array($action) || ! self::hasExactKeys($action, self::POST_DEPLOYMENT_KEYS)) {
            throw new InvalidArgumentException(
                "Post-deployment action [#{$index}] must have exactly the keys target, operation, reason, recovery, and verification.",
            );
        }
        $fields = [];
        foreach (self::POST_DEPLOYMENT_KEYS as $key) {
            /** @var mixed $value */
            $value = $action[$key];
            if (! is_string($value) || trim($value) === '' || str_contains($value, "\0")) {
                throw new InvalidArgumentException(
                    "Post-deployment action [#{$index}] field [{$key}] must be a non-empty string free of NUL bytes.",
                );
            }
            $fields[$key] = $value;
        }

        return [
            'target' => $fields['target'],
            'operation' => $fields['operation'],
            'reason' => $fields['reason'],
            'recovery' => $fields['recovery'],
            'verification' => $fields['verification'],
        ];
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string> $keys
     */
    private static function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_map(strval(...), array_keys($value));
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);

        return $actual === $keys;
    }

    /** @return array{setup:list<array{id:string,node:string,argv:list<string>,timeout_seconds:int}>,acceptance:list<array{id:string,node:string,argv:list<string>,timeout_seconds:int}>,post_deployment_actions:list<array{target:string,operation:string,reason:string,recovery:string,verification:string}>} */
    public function toArray(): array
    {
        return [
            'setup' => $this->setup,
            'acceptance' => $this->acceptance,
            'post_deployment_actions' => $this->postDeploymentActions,
        ];
    }
}
