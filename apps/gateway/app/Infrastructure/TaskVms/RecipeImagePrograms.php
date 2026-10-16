<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\PhpBuildInstaller;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use RuntimeException;

/** Runs only code-owned installers; the persisted recipe supplies package names and integrity pins. */
final readonly class RecipeImagePrograms
{
    public function apply(TaskVmGuestShell $shell, MachineRecipe $recipe, string $profile, string $phase): void
    {
        $payload = $this->payload($shell, $recipe, $profile);
        if ($phase === 'pi') {
            if (! isset($payload['tools']['pi'])) {
                return;
            }
            $pin = $recipe->tools['pi']->artifacts[$payload['architecture']];
            $installed = $shell->asUser(['sha256sum', TaskVmCloudInit::Home.'/'.TaskVmRuntime::PiPath]);
            if ($installed->succeeded() && str_starts_with($installed->stdout, $pin->digest.' ')) {
                return;
            }
            $file = config()->string('orbit.home').'/sandbox-artifacts/'.$pin->location;
            if (! is_file($file) || hash_file($pin->algorithm, $file) !== $pin->digest) {
                throw new TaskVmException('task_vm.image_build_failed', 'The recipe Pi artifact is missing or has the wrong digest.');
            }
            $result = $shell->asUser(['bash', '-ceu', TaskVmRuntime::InstallPi, '--', $pin->digest], ProtectedInput::fromFile($file, '', TaskVmRuntime::ArtifactMaxBytes));
        } else {
            $result = $shell->asRoot(['python3', '-', $phase, json_encode($payload, JSON_THROW_ON_ERROR)], $this->script(), timeout: 1440);
        }
        if (! $result->succeeded() || $result->truncated) {
            throw new TaskVmException('task_vm.image_build_failed', "Recipe image phase [{$profile}:{$phase}] failed.");
        }
    }

    public function verify(TaskVmGuestShell $shell, MachineRecipe $recipe, string $profile): void
    {
        $result = $shell->asRoot(['python3', '-', 'verify', json_encode($this->payload($shell, $recipe, $profile), JSON_THROW_ON_ERROR)], $this->script(), timeout: 120);
        if (! $result->succeeded() || $result->truncated || trim($result->stdout) !== '{"ok":true}') {
            throw new TaskVmException('task_vm.image_build_failed', "Recipe image [{$profile}] failed software verification.");
        }
    }

    /** @return array{identity: string, snapshot: string, artifact_mirror: string, git_packages: list<string>, architecture: string, profile: string, tools: array<string, mixed>, php: array<string, mixed>|null} */
    private function payload(TaskVmGuestShell $shell, MachineRecipe $recipe, string $profile): array
    {
        $arch = $shell->asRoot(['dpkg', '--print-architecture']);
        $architecture = trim($arch->stdout);
        if (! $arch->succeeded() || ! in_array($architecture, ['amd64', 'arm64'], true)) {
            throw new TaskVmException('task_vm.image_build_failed', 'The image architecture is unsupported.');
        }
        $names = array_unique([...$recipe->profile('base', $architecture), ...$recipe->profile($profile, $architecture)]);
        $data = json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! is_array($data['tools'] ?? null)) {
            throw new RuntimeException('The stored recipe tools are invalid.');
        }
        $tools = [];
        foreach ($names as $name) {
            if (! array_key_exists($name, $data['tools'])) {
                throw new RuntimeException('A selected recipe tool is missing.');
            }
            $tools[$name] = $data['tools'][$name];
        }

        $versions = array_map(strval(...), array_keys($recipe->phpExtensions(array_keys($tools))));
        $catalog = new RemoteProgramCatalog;
        // The image installs its PHP build with php.install-build, the one installer (ADR 0216). Images run no PHP-FPM.
        $php = $versions === [] ? null : [
            'program' => $catalog->program(PhpBuildInstaller::Program)->source,
            'bootstrap' => $catalog->bootstrap(),
            'input' => PhpBuildInstaller::input($recipe, $versions, 'apply', in_array($profile, $recipe->phpPolicy->pcovProfiles, true), 'refuse', 'none', opentelemetry: in_array($profile, $recipe->phpPolicy->opentelemetryProfiles, true)),
        ];

        return ['identity' => $recipe->identity, 'snapshot' => $recipe->snapshot, 'architecture' => $architecture,
            'profile' => $profile, 'tools' => $tools, 'php' => $php, 'git_packages' => $recipe->gitPackages, 'artifact_mirror' => $recipe->artifactMirror];
    }

    private function script(): string
    {
        $shared = file_get_contents(resource_path('remote/lib/recipe.py'));
        $image = file_get_contents(resource_path('machine-software/build-image.py'));
        if ($shared === false || $image === false) {
            throw new RuntimeException('The recipe image program is missing.');
        }

        // Reuse the checked download, archive extraction and platform helpers without invoking the Node entry point.
        $shared = file_get_contents(resource_path('remote/lib/artifacts.py'))."\n".$shared;

        return str_replace("if __name__ == '__main__':\n    sys.exit(main())", '', $shared)."\n".$image;
    }
}
