<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\RouteApplicationUrlInspector;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use Throwable;

/**
 * Reads, as root, the `.env` of each application directory in one SSH command on the Instance's Node. It
 * follows the writer's `artisan` rule and parses `APP_URL` as the private Route check does.
 */
final readonly class NativeRouteApplicationUrlInspector implements RouteApplicationUrlInspector
{
    public function __construct(
        private DevelopmentSshExecutor $ssh,
        private CommandDeadline $deadline,
    ) {}

    public function inspect(Instance $instance, array $expected): array
    {
        if ($expected === []) {
            return [];
        }

        $instance->loadMissing('node');
        $directories = array_map(strval(...), array_keys($expected));
        $arguments = ['sudo', 'bash', '-seu', '--', rtrim($instance->checkout_path, '/')];

        foreach ($directories as $directory) {
            if ($directory !== '' && ! RelativeWebRoot::isValid($directory)) {
                throw new DoctorInspectionException;
            }

            $arguments[] = $directory;
            $arguments[] = $expected[$directory];
        }

        try {
            $result = $this->ssh->execute(
                $instance->node,
                new RemoteCommand(arguments: $arguments, input: <<<'BASH'
                    checkout=$1
                    shift
                    index=0
                    while [ "$#" -ge 2 ]; do
                        directory=$1
                        expected_url=$2
                        shift 2
                        if [ "$directory" = '' ]; then
                            root=$checkout
                        else
                            root=$checkout/$directory
                        fi
                        environment=$root/.env
                        if [ ! -f "$root/artisan" ] || [ -L "$root/artisan" ]; then
                            printf '%s=1\n' "$index"
                        elif [ ! -f "$environment" ] || [ -L "$environment" ]; then
                            printf '%s=0\n' "$index"
                        elif awk -v expected="$expected_url" '
                            BEGIN { found = 0 }
                            $0 ~ /^APP_URL=/ {
                                found += 1
                                value = substr($0, 9)
                                if (value ~ /^".*"$/) {
                                    value = substr(value, 2, length(value) - 2)
                                }
                                if (value != expected) { exit 1 }
                            }
                            END { if (found != 1) exit 1 }
                        ' "$environment"; then
                            printf '%s=1\n' "$index"
                        else
                            printf '%s=0\n' "$index"
                        fi
                        index=$((index + 1))
                    done
                    BASH),
                step: 'doctor-route-application-url',
                errorCode: 'instance.inspection_failed',
                commandTimeout: $this->deadline->cap(30.0),
            );
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }

        $observed = [];
        foreach (explode("\n", trim($result->stdout)) as $line) {
            [$index, $value] = array_pad(explode('=', $line, 2), 2, null);
            if (is_string($index) && ctype_digit($index) && in_array($value, ['0', '1'], true)) {
                $observed[(int) $index] = $value === '1';
            }
        }

        $matches = [];
        foreach ($directories as $index => $directory) {
            $matches[$directory] = $observed[$index] ?? throw new DoctorInspectionException;
        }

        return $matches;
    }
}
