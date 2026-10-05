<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\InstanceAppInspectionData;
use App\Domain\Doctor\InstanceAppStateInspector;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

final readonly class NativeInstanceAppStateInspector implements InstanceAppStateInspector
{
    public function __construct(private DevelopmentSshExecutor $ssh, private DevelopmentInstanceConfigurator $configuration) {}

    public function inspectApp(Instance $instance, string $app): InstanceAppInspectionData
    {
        try {
            $directory = $instance->applicationDirectory($app);
            $webRoot = $instance->appConfiguration($app)['web_root'];
            $result = $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['python3', '-', $directory, $webRoot === null ? $directory : $directory.'/'.$webRoot],
                input: <<<'PYTHON'
                    import pathlib, sys
                    paths = [pathlib.Path(value) for value in sys.argv[1:]]
                    print('present' if all(path.is_dir() and path.resolve() == path for path in paths) else 'missing')
                    PYTHON,
            ), 'doctor-instance-app', 'doctor.instance_app_inspection_failed', commandTimeout: 30.0);
            $pathMatches = trim($result->stdout) === 'present';
            if (! in_array(trim($result->stdout), ['present', 'missing'], true)) {
                throw new DoctorInspectionException;
            }
            if (! $pathMatches) {
                return new InstanceAppInspectionData(false, false);
            }
            $profile = $this->configuration->inspect($instance, $app);
            $recorded = $instance->runtimeForApp($app);

            return new InstanceAppInspectionData(true, $profile->phpVersion === $recorded['php_version'] && $profile->laravel === $recorded['laravel']);
        } catch (RuntimeConvergenceException $exception) {
            if ($exception->errorCode === 'app-dev.source_metadata_unsafe') {
                return new InstanceAppInspectionData(false, false);
            }
            if (in_array($exception->errorCode, ['app-dev.php_version_unsupported', 'app-dev.laravel_source_invalid'], true)) {
                return new InstanceAppInspectionData(true, false);
            }
            throw new DoctorInspectionException;
        }
    }
}
