<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofFixtures;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use RuntimeException;
use Throwable;

/**
 * Stage the proof-only fixtures of one issue on every role of a proof attempt.
 *
 * The files come from `proofs/<issue>/` at the exact candidate
 * commit, never from the host working tree, and land root-owned and read-only
 * for the orbit user at `/var/lib/orbit-e2e/proof/<name>` on every role,
 * including roles without a checkout. Each role then reports the installed
 * inventory, which must equal the digest computed on the host. The guest
 * script inventory stays closed; fixtures are a separate, per-issue layer.
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect Staging keeps its exact ordered guest batches together.
 */
final readonly class ProofFixtureStager
{
    /** The host guest-command helper rejects batches above this size. */
    private const int MAX_BATCH_REQUESTS = 128;

    /**
     * Replace the guest fixture directory. A promoted generation can carry another
     * issue's fixtures, and the inventory check demands this issue's files only.
     */
    private const string DIRECTORY_SCRIPT = 'set -eu; rm -rf -- "$1"; install -d -o root -g root -m 0755 "$1"';

    /** The guest prints the installed inventory in the exact host layout. */
    private const string INVENTORY_SCRIPT =
        'cd -- "$1" && test -z "$(find . -mindepth 1 ! -type f ! -type d)" '
            .'&& find . -mindepth 1 -type f -printf \'%P\\n\' | LC_ALL=C sort | while IFS= read -r f; do '
            .'printf \'%s\\t%s\\t%s\\n\' "$f" "$(stat -c %a -- "$f")" "$(sha256sum -- "$f" | cut -c1-64)"; done';

    public function __construct(
        private GuestTransport $incus,
        private OperationId $operation,
    ) {}

    /**
     * Read the fixture inventory of the candidate commit without touching a guest.
     *
     * @param list<string> $additionalIssues
     * @return array<string, array{mode:string, sha256:string, content:string}>
     */
    public function inventory(
        GitRepository $repository,
        string $candidateSha,
        string $issue,
        array $additionalIssues = [],
    ): array {
        if (
            in_array($issue, $additionalIssues, true)
            || count($additionalIssues) !== count(array_unique($additionalIssues))
        ) {
            throw new RuntimeException('The additional proof fixture issue list is invalid.');
        }
        /** @return array<string, array{mode:string, sha256:string, content:string}> */
        $issueInventory = static function (string $fixtureIssue) use ($repository, $candidateSha): array {
            TopologyTarget::assertIssue($fixtureIssue);
            $issueFiles = [];
            foreach ($repository->directoryBlobs(
                $candidateSha,
                ProofFixtures::hostDirectory($fixtureIssue),
            ) as $name => $blob) {
                if (! ProofFixtures::isFixtureName($name)) {
                    throw new RuntimeException("Proof fixture [{$name}] has an invalid file name.");
                }
                $issueFiles[$name] = [
                    'mode' => $blob['mode'] === '100755' ? '755' : '644',
                    'sha256' => hash('sha256', $blob['content']),
                    'content' => $blob['content'],
                ];
            }

            return $issueFiles;
        };

        $files = $issueInventory($issue);
        foreach ($additionalIssues as $additionalIssue) {
            TopologyTarget::assertIssue($additionalIssue);
            foreach ($issueInventory($additionalIssue) as $name => $file) {
                $files["{$additionalIssue}/{$name}"] = $file;
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /** @param list<string> $additionalIssues */
    public function stage(
        TopologyTarget $target,
        GitRepository $repository,
        string $candidateSha,
        array $additionalIssues = [],
    ): ProofFixtures {
        $inventory = $this->inventory($repository, $candidateSha, $target->issue, $additionalIssues);
        $files = array_map(
            static fn (array $file): array => ['mode' => $file['mode'], 'sha256' => $file['sha256']],
            $inventory,
        );
        $digest = ProofFixtures::digestOf($files);
        $instances = [];
        foreach (TopologyProfile::ROLES as $role) {
            $instances[$role] = $target->instance($role);
        }
        $temporaryDirectory = $this->temporaryDirectory();
        try {
            $this->materialize($inventory, $temporaryDirectory);
            $roles = $this->install($instances, $inventory, $temporaryDirectory, $files);
        } finally {
            $this->removeTemporaryDirectory($temporaryDirectory);
        }

        return new ProofFixtures($files, $digest, $roles);
    }

    /**
     * @param array<string, string> $instances
     * @param array<string, array{mode:string, sha256:string, content:string}> $inventory
     * @param array<string, array{mode:string, sha256:string}> $files
     * @return array<string, string>
     */
    private function install(
        array $instances,
        array $inventory,
        string $temporaryDirectory,
        array $files,
    ): array {
        $prefix = '/var/lib/orbit-e2e/proof-staging/'.$this->operation->value;
        $staged = $instances;
        try {
            $prepare = [];
            foreach ($instances as $role => $instance) {
                $prepare["fixture-prepare.{$role}"] = [
                    'instance' => $instance,
                    'command' => new GuestCommand(['install', '-d', '-m', '0700', $prefix]),
                ];
            }
            $this->assertBatchSuccessful($this->incus->execAll($prepare), 'Proof fixture staging failed.');

            $pushes = [];
            foreach ($instances as $role => $instance) {
                foreach (array_keys($inventory) as $index => $name) {
                    $stagedName = hash('sha256', $name);
                    $pushes["fixture-push.{$role}.{$index}"] = [
                        'instance' => $instance,
                        'source' => "{$temporaryDirectory}/{$name}",
                        'destination' => "{$prefix}/{$stagedName}",
                    ];
                }
            }
            if ($pushes !== []) {
                $this->incus->pushFiles($pushes);
            }

            $installs = [];
            foreach ($instances as $role => $instance) {
                $installs["fixture-directory.{$role}"] = [
                    'instance' => $instance,
                    'command' => new GuestCommand([
                        'sh',
                        '-c',
                        self::DIRECTORY_SCRIPT,
                        'orbit-e2e',
                        ProofFixtures::GUEST_DIRECTORY,
                    ]),
                ];
            }
            $this->assertBatchSuccessful($this->incus->execAll($installs), 'Proof fixture directory failed.');

            $directories = [];
            foreach (array_keys($inventory) as $name) {
                $directory = dirname($name);
                if ($directory !== '.') {
                    $directories[$directory] = true;
                }
            }
            if ($directories !== []) {
                $parents = [];
                foreach ($instances as $role => $instance) {
                    foreach (array_keys($directories) as $directory) {
                        $parents["fixture-parent.{$role}.{$directory}"] = [
                            'instance' => $instance,
                            'command' => new GuestCommand([
                                'install',
                                '-d',
                                '-o',
                                'root',
                                '-g',
                                'root',
                                '-m',
                                '0755',
                                ProofFixtures::GUEST_DIRECTORY.'/'.$directory,
                            ]),
                        ];
                    }
                }
                $this->assertBatchSuccessful($this->incus->execAll($parents), 'Proof fixture parent directory failed.');
            }

            $installs = [];
            foreach ($instances as $role => $instance) {
                foreach (array_keys($inventory) as $index => $name) {
                    $file = $inventory[$name];
                    $stagedName = hash('sha256', $name);
                    $installs["fixture-install.{$role}.{$index}"] = [
                        'instance' => $instance,
                        'command' => new GuestCommand([
                            'install',
                            '-o',
                            'root',
                            '-g',
                            'root',
                            '-m',
                            '0'.$file['mode'],
                            "{$prefix}/{$stagedName}",
                            ProofFixtures::guestPath($name),
                        ]),
                    ];
                }
            }
            if ($installs !== []) {
                foreach (array_chunk($installs, self::MAX_BATCH_REQUESTS, true) as $batch) {
                    $this->assertBatchSuccessful(
                        $this->incus->execAll($batch),
                        'Proof fixture installation failed.',
                    );
                }
            }

            $roles = $this->verify($instances, $files);

            $cleanupInstances = $staged;
            $staged = [];
            $this->cleanup($cleanupInstances, $prefix);

            return $roles;
        } catch (RuntimeException $primary) {
            if ($staged !== []) {
                try {
                    $this->cleanup($staged, $prefix);
                } catch (RuntimeException $cleanupFailure) {
                    throw new RuntimeException(
                        'Proof fixture staging failed: '.$primary->getMessage().'; cleanup also failed: '
                            .$cleanupFailure->getMessage(),
                        0,
                        $primary,
                    );
                }
            }
            throw $primary;
        }
    }

    /**
     * Every role prints its installed inventory; the digest of that text must equal the host digest.
     *
     * @param array<string, string> $instances
     * @param array<string, array{mode:string, sha256:string}> $files
     * @return array<string, string>
     */
    private function verify(array $instances, array $files): array
    {
        $expected = ProofFixtures::inventoryText($files);
        $probes = [];
        foreach ($instances as $role => $instance) {
            $probes["fixture-verify.{$role}"] = [
                'instance' => $instance,
                'command' => new GuestCommand(
                    ['sh', '-c', self::INVENTORY_SCRIPT, 'orbit-e2e', ProofFixtures::GUEST_DIRECTORY],
                    60,
                ),
            ];
        }
        $results = $this->incus->execAll($probes);
        $this->assertBatchSuccessful($results, 'Proof fixture verification failed.');
        $roles = [];
        foreach (array_keys($instances) as $role) {
            $probe = $results["fixture-verify.{$role}"] ?? null;
            if (! $probe instanceof GuestCommandResult) {
                throw new RuntimeException('Proof fixture verification batch result is invalid.');
            }
            if ($probe->stdout !== $expected) {
                throw new RuntimeException("Role [{$role}] does not hold the staged proof fixture inventory.");
            }
            $roles[$role] = hash('sha256', $probe->stdout);
        }

        return $roles;
    }

    /** @param array<string, string> $instances */
    private function cleanup(array $instances, string $prefix): void
    {
        $commands = [];
        foreach ($instances as $role => $instance) {
            $commands["fixture-cleanup.{$role}"] = [
                'instance' => $instance,
                'command' => new GuestCommand(['rm', '-rf', '--', $prefix]),
            ];
        }
        $this->assertBatchSuccessful($this->incus->execAll($commands), 'Proof fixture staging cleanup failed.');
    }

    /** @param array<string, GuestCommandResult> $results */
    private function assertBatchSuccessful(array $results, string $message): void
    {
        $failed = [];
        foreach ($results as $label => $result) {
            if (! $result->successful()) {
                $failed[] = $label;
            }
        }
        if ($failed !== []) {
            throw new RuntimeException($message.' Failed operations: '.implode(', ', $failed).'.');
        }
    }

    /** @param array<string, array{mode:string, sha256:string, content:string}> $inventory */
    private function materialize(array $inventory, string $directory): void
    {
        foreach ($inventory as $name => $file) {
            $path = "{$directory}/{$name}";
            $parent = dirname($path);
            if (! is_dir($parent) && ! mkdir($parent, 0700, true)) {
                throw new RuntimeException('Could not stage the candidate proof fixtures.');
            }
            if (file_put_contents($path, $file['content'], LOCK_EX) === false || ! chmod($path, 0600)) {
                throw new RuntimeException('Could not stage the candidate proof fixtures.');
            }
        }
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().'/orbit-proof-fixtures-'.bin2hex(random_bytes(12));
        if (! mkdir($path, 0700)) {
            throw new RuntimeException('Could not create the proof fixture staging directory.');
        }

        return $path;
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        try {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                /** @var \SplFileInfo $entry */
                if ($entry->isDir()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($directory);
        } catch (Throwable) {
            // The staging directory holds only committed, non-secret fixture content.
        }
    }
}
