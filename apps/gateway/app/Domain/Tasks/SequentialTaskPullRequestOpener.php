<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

final readonly class SequentialTaskPullRequestOpener implements TaskPullRequestOpener
{
    /**
     * @param  list<TaskPullRequestOpener>  $openers
     */
    public function __construct(private array $openers) {}

    public function open(TaskGroup $group): ?string
    {
        foreach ($this->openers as $opener) {
            $url = $opener->open($group);

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }
}
