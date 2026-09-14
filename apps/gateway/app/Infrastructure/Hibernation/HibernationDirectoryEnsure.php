<?php

declare(strict_types=1);

namespace App\Infrastructure\Hibernation;

/**
 * Creates hibernation marker and access-log directories so the caddy user
 * can traverse ancestors and write logs after the Caddy publish umask.
 */
final readonly class HibernationDirectoryEnsure
{
    /**
     * Host roots the ensure step must not chmod. Changing these would strip
     * sticky bits or alter operating-system mounts.
     *
     * @var list<string>
     */
    public const array ProtectedAncestors = [
        '/dev',
        '/dev/shm',
        '/etc',
        '/proc',
        '/run',
        '/sys',
        '/tmp',
        '/var',
        '/var/tmp',
    ];

    public static function script(string $markersExpression = '"$1"', string $logsExpression = '"$2"'): string
    {
        $protected = implode('|', self::ProtectedAncestors);

        return <<<BASH
            ensure_hibernation_ancestor() {
                current=\$(dirname -- "\$1")
                while [ "\$current" != / ] && [ "\$current" != . ]; do
                    case "\$current" in
                        {$protected})
                            break
                            ;;
                    esac
                    install -d -m 0755 -- "\$current"
                    current=\$(dirname -- "\$current")
                done
            }
            ensure_hibernation_ancestor {$markersExpression}
            install -d -o root -g caddy -m 0755 -- {$markersExpression}
            ensure_hibernation_ancestor {$logsExpression}
            install -d -o root -g caddy -m 2775 -- {$logsExpression}
            BASH;
    }
}
