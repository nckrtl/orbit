<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * The declared input of one proof: ordered setup and acceptance actions the
 * runner executes as exact argument vectors.
 *
 * The plan is validated once at construction and never carries stdin: a proof
 * must not hold secrets, and the record stores the plan verbatim.
 */
/** @mago-expect lint:cyclomatic-complexity,kan-defect Every plan rule is checked fail-closed in one place. */
final readonly class ProofPlan
{
    public const int MAX_TIMEOUT_SECONDS = 900;

    public const int MAX_ID_LENGTH = 64;

    private const array SECTIONS = ['setup', 'acceptance'];

    /** Optional: a plan that mutates the topology cannot become the standby. */
    private const string MUTATES = 'mutates';

    /** Optional: the topology the plan ends with; a node it leaves out is proved absent. */
    private const string ENDS_WITH = 'ends_with';

    /** Optional: additional issue fixture directories staged from the same candidate. */
    private const string FIXTURE_ISSUES = 'fixture_issues';

    private const array ACTION_KEYS = ['id', 'node', 'argv', 'timeout_seconds'];

    private const string EXPECTED_EXIT_CODE = 'expected_exit_code';

    private const array EXPECTED_EXIT_CODES = [0, 124, 137];

    /**
     * @param list<array{id:string,node:string,argv:list<string>,timeout_seconds:int,expected_exit_code:int}> $setup
     * @param list<array{id:string,node:string,argv:list<string>,timeout_seconds:int,expected_exit_code:int}> $acceptance
     * @param list<string> $fixtureIssues
     */
    private function __construct(
        public array $setup,
        public array $acceptance,
        public bool $mutates,
        public TopologyEndState $endsWith,
        public array $fixtureIssues,
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
        $mutates = false;
        if (array_key_exists(self::MUTATES, $plan)) {
            if (! is_bool($plan[self::MUTATES])) {
                throw new InvalidArgumentException('The proof plan key mutates must be a boolean.');
            }
            $mutates = $plan[self::MUTATES];
            unset($plan[self::MUTATES]);
        }
        $endsWith = TopologyEndState::complete();
        if (array_key_exists(self::ENDS_WITH, $plan)) {
            $endsWith = TopologyEndState::fromArray($plan[self::ENDS_WITH]);
            unset($plan[self::ENDS_WITH]);
        }
        $fixtureIssues = [];
        if (array_key_exists(self::FIXTURE_ISSUES, $plan)) {
            $fixtureIssues = self::fixtureIssues($plan[self::FIXTURE_ISSUES]);
            unset($plan[self::FIXTURE_ISSUES]);
        }
        // Removing a node changes the topology the proof ran on, whatever the plan says.
        $mutates = $mutates || $endsWith->declaresAbsence();
        $keys = array_keys($plan);
        sort($keys, SORT_STRING);
        $expected = self::SECTIONS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new InvalidArgumentException(
                'The proof plan must have exactly the keys setup and acceptance, '
                .'plus optional mutates, ends_with, and fixture_issues.',
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

        return new self($setup, $acceptance, $mutates, $endsWith, $fixtureIssues);
    }

    /**
     * @param list<mixed> $declared
     * @param array<string, true> $ids
     * @return list<array{id:string,node:string,argv:list<string>,timeout_seconds:int,expected_exit_code:int}>
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
            if (! is_array($action)) {
                throw new InvalidArgumentException(
                    "Proof action [{$label}] must have exactly the keys id, node, argv, and timeout_seconds, "
                    .'plus an optional expected_exit_code.',
                );
            }
            $keys = self::ACTION_KEYS;
            if (array_key_exists(self::EXPECTED_EXIT_CODE, $action)) {
                $keys[] = self::EXPECTED_EXIT_CODE;
            }
            if (! self::hasExactKeys($action, $keys)) {
                throw new InvalidArgumentException(
                    "Proof action [{$label}] must have exactly the keys id, node, argv, and timeout_seconds, "
                    .'plus an optional expected_exit_code.',
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
            $expectedExitCode = array_key_exists(self::EXPECTED_EXIT_CODE, $action)
                ? $action[self::EXPECTED_EXIT_CODE]
                : 0;
            if (! is_int($expectedExitCode) || ! in_array($expectedExitCode, self::EXPECTED_EXIT_CODES, true)) {
                throw new InvalidArgumentException(
                    "Proof action [{$id}] must expect exit code 0, 124, or 137.",
                );
            }
            $actions[] = [
                'id' => $id,
                'node' => $node,
                'argv' => $arguments,
                'timeout_seconds' => $timeout,
                'expected_exit_code' => $expectedExitCode,
            ];
        }

        return $actions;
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $plan = [
            'setup' => self::serializedActions($this->setup),
            'acceptance' => self::serializedActions($this->acceptance),
        ];
        if ($this->mutates) {
            $plan['mutates'] = true;
        }
        if ($this->endsWith->declaresAbsence()) {
            $plan['ends_with'] = $this->endsWith->toArray();
        }
        if ($this->fixtureIssues !== []) {
            $plan['fixture_issues'] = $this->fixtureIssues;
        }

        return $plan;
    }

    /** @return list<string> */
    private static function fixtureIssues(mixed $declared): array
    {
        if (! is_array($declared) || ! array_is_list($declared) || $declared === []) {
            throw new InvalidArgumentException('The proof fixture issue list is invalid.');
        }
        $issues = [];
        foreach ($declared as $issue) {
            if (! is_string($issue) || in_array($issue, $issues, true)) {
                throw new InvalidArgumentException('The proof fixture issue list is invalid.');
            }
            try {
                TopologyTarget::assertIssue($issue);
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException('The proof fixture issue list is invalid.');
            }
            $issues[] = $issue;
        }

        return $issues;
    }

    /**
     * @param list<array{id:string,node:string,argv:list<string>,timeout_seconds:int,expected_exit_code:int}> $actions
     * @return list<array<string, mixed>>
     */
    private static function serializedActions(array $actions): array
    {
        return array_map(static function (array $action): array {
            if ($action['expected_exit_code'] === 0) {
                unset($action['expected_exit_code']);
            }

            return $action;
        }, $actions);
    }
}
