<?php

declare(strict_types=1);

namespace App\Infrastructure;

final readonly class SharedOrbitDirectory
{
    public function convergenceFunction(): string
    {
        return <<<'BASH'
            converge_shared_orbit_directory() {
                local directory="$1"
                local conflict_exit="$2"

                if [ -e "$directory" ] || [ -L "$directory" ]; then
                    if [ ! -d "$directory" ] || [ -L "$directory" ] \
                        || [ "$(stat -c '%U:%G' -- "$directory" 2>/dev/null)" != root:root ]
                    then
                        return "$conflict_exit"
                    fi
                elif ! install -d -o root -g root -m 0711 -- "$directory"; then
                    return "$conflict_exit"
                fi

                if [ ! -d "$directory" ] || [ -L "$directory" ] \
                    || [ "$(stat -c '%U:%G' -- "$directory" 2>/dev/null)" != root:root ]
                then
                    return "$conflict_exit"
                fi

                chmod 0711 -- "$directory"
                [ "$(stat -c '%U:%G:%a' -- "$directory" 2>/dev/null)" = root:root:711 ] \
                    || return "$conflict_exit"
            }
            BASH;
    }
}
