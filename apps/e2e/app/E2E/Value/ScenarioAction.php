<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class ScenarioAction
{
    public function __construct(
        public string $phase,
        public string $name,
        public int $deadlineSeconds,
        public bool $required = true,
    ) {
        if (! in_array($phase, ['setup', 'exercise', 'assertion'], true)) {
            throw new InvalidArgumentException('The scenario action phase is invalid.');
        }
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $name) !== 1) {
            throw new InvalidArgumentException('The scenario action name is invalid.');
        }
        if ($deadlineSeconds < 1 || $deadlineSeconds > 7200) {
            throw new InvalidArgumentException('The scenario action deadline is invalid.');
        }
    }

    /** @return array{phase:string,name:string,deadline_seconds:int,required:bool} */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase,
            'name' => $this->name,
            'deadline_seconds' => $this->deadlineSeconds,
            'required' => $this->required,
        ];
    }
}
