<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * The immutable, canonical inventory that makes one successful proof reusable.
 *
 * @mago-expect lint:cyclomatic-complexity,excessive-parameter-list The immutable evidence schema validates every independent input field.
 */
final readonly class ProofInputManifest
{
    public const int SCHEMA = 3;

    /**
     * @param list<string> $featureRuntimePaths
     * @param list<array{path:string,classification:string,mode:string,blob:string}> $staticInputs
     * @param list<string> $extraInputs
     * @param array{static_classification:bool,proof_contract:bool,checkout_literals:bool,observed_processes:bool,observed_paths:bool,pcov_cleanup:bool} $completeness
     */
    public function __construct(
        public int $policyVersion,
        public string $provedSha,
        public string $includedMainSha,
        public array $featureRuntimePaths,
        public array $staticInputs,
        public string $proofPlanPath,
        public array $extraInputs,
        public ?ObservedPhpInputs $observedInputs,
        public array $completeness,
    ) {
        foreach ([$provedSha, $includedMainSha] as $sha) {
            if (preg_match('/\A[0-9a-f]{40}\z/D', $sha) !== 1) {
                throw new InvalidArgumentException('A proof-input manifest Git identity is invalid.');
            }
        }
        if ($policyVersion < 1 || ! $this->safePath($proofPlanPath)) {
            throw new InvalidArgumentException('The proof-input manifest policy is invalid.');
        }
        $this->assertOrderedPaths($featureRuntimePaths, 'feature runtime');
        $this->assertOrderedPaths($extraInputs, 'extra input');
        if (
            array_keys($completeness) !== [
                'static_classification',
                'proof_contract',
                'checkout_literals',
                'observed_processes',
                'observed_paths',
                'pcov_cleanup',
            ]
            || ! array_all($completeness, static fn (mixed $value): bool => is_bool($value))
        ) {
            throw new InvalidArgumentException('The proof-input manifest completeness result is invalid.');
        }
        $paths = [];
        foreach ($staticInputs as $input) {
            if (
                array_keys($input) !== ['path', 'classification', 'mode', 'blob']
                || ! $this->safePath($input['path'])
                || ! in_array(
                    $input['classification'],
                    [
                        ProofInputClassification::Runtime->value,
                        ProofInputClassification::ProofContract->value,
                    ],
                    true,
                )
                || preg_match('/\A[0-7]{6}\z/D', $input['mode']) !== 1
                || preg_match('/\A[0-9a-f]{40}\z/D', $input['blob']) !== 1
                || isset($paths[$input['path']])
            ) {
                throw new InvalidArgumentException('A proof-input manifest entry is invalid.');
            }
            $paths[$input['path']] = true;
        }
        $ordered = array_keys($paths);
        $sorted = $ordered;
        sort($sorted, SORT_STRING);
        if ($ordered !== $sorted) {
            throw new InvalidArgumentException('Proof-input manifest entries must be ordered.');
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode(
            $this->payload(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...$this->payload(), 'fingerprint' => $this->fingerprint()];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== [
                'schema',
                'policy_version',
                'proved_sha',
                'included_main_sha',
                'feature_runtime_paths',
                'static_inputs',
                'proof_contract',
                'observed_inputs',
                'completeness',
                'fingerprint',
            ]
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! is_int($value['policy_version'])
            || ! is_string($value['proved_sha'])
            || ! is_string($value['included_main_sha'])
            || ! is_array($value['feature_runtime_paths'])
            || ! is_array($value['static_inputs'])
            || ! is_array($value['proof_contract'])
            || array_keys($value['proof_contract']) !== ['plan_path', 'extra_inputs']
            || ! is_string($value['proof_contract']['plan_path'])
            || ! is_array($value['proof_contract']['extra_inputs'])
            || ! is_array($value['completeness'])
            || $value['observed_inputs'] !== null
            && ! is_array($value['observed_inputs'])
            || ! is_string($value['fingerprint'])
            || ! array_is_list($value['feature_runtime_paths'])
            || ! array_all($value['feature_runtime_paths'], static fn (mixed $item): bool => is_string($item))
            || ! array_is_list($value['static_inputs'])
            || ! array_all($value['static_inputs'], static fn (mixed $item): bool => is_array($item))
            || ! array_is_list($value['proof_contract']['extra_inputs'])
            || ! array_all(
                $value['proof_contract']['extra_inputs'],
                static fn (mixed $item): bool => is_string($item),
            )
        ) {
            throw new InvalidArgumentException('The proof-input manifest schema is invalid.');
        }
        /** @var list<string> $featureRuntimePaths */
        $featureRuntimePaths = array_values($value['feature_runtime_paths']);
        /** @var list<array{path:string,classification:string,mode:string,blob:string}> $staticInputs */
        $staticInputs = array_values($value['static_inputs']);
        /** @var list<string> $extraInputs */
        $extraInputs = array_values($value['proof_contract']['extra_inputs']);
        /** @var array{static_classification:bool,proof_contract:bool,checkout_literals:bool,observed_processes:bool,observed_paths:bool,pcov_cleanup:bool} $completeness */
        $completeness = $value['completeness'];
        $observedInputs = is_array($value['observed_inputs'])
            ? ObservedPhpInputs::fromArray($value['observed_inputs'])
            : null;
        $manifest = new self(
            $value['policy_version'],
            $value['proved_sha'],
            $value['included_main_sha'],
            $featureRuntimePaths,
            $staticInputs,
            $value['proof_contract']['plan_path'],
            $extraInputs,
            $observedInputs,
            $completeness,
        );
        if (! hash_equals($manifest->fingerprint(), $value['fingerprint'])) {
            throw new InvalidArgumentException('The proof-input manifest fingerprint is invalid.');
        }

        return $manifest;
    }

    /** @return array<string, true> */
    public function inputPaths(): array
    {
        $paths = [];
        foreach ($this->staticInputs as $input) {
            $paths[$input['path']] = true;
        }
        if ($this->observedInputs !== null) {
            $paths += $this->observedInputs->paths();
        }

        return $paths;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema' => self::SCHEMA,
            'policy_version' => $this->policyVersion,
            'proved_sha' => $this->provedSha,
            'included_main_sha' => $this->includedMainSha,
            'feature_runtime_paths' => $this->featureRuntimePaths,
            'static_inputs' => $this->staticInputs,
            'proof_contract' => [
                'plan_path' => $this->proofPlanPath,
                'extra_inputs' => $this->extraInputs,
            ],
            'observed_inputs' => $this->observedInputs?->toArray(),
            'completeness' => $this->completeness,
        ];
    }

    /** @param list<mixed> $paths */
    private function assertOrderedPaths(array $paths, string $label): void
    {
        if (
            ! array_is_list($paths)
            || ! array_all(
                $paths,
                fn (mixed $path): bool => is_string($path)
                && $this->safePath($path),
            )
        ) {
            throw new InvalidArgumentException("The proof-input manifest {$label} paths are invalid.");
        }
        $sorted = $paths;
        sort($sorted, SORT_STRING);
        if ($paths !== array_values(array_unique($sorted))) {
            throw new InvalidArgumentException("The proof-input manifest {$label} paths must be ordered and unique.");
        }
    }

    private function safePath(string $path): bool
    {
        return (
            $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && ! str_contains($path, '\\')
            && preg_match('/[\r\n]/', $path) !== 1
            && ! in_array('', explode('/', $path), true)
            && ! in_array('.', explode('/', $path), true)
            && ! in_array('..', explode('/', $path), true)
        );
    }
}
