<?php

declare(strict_types=1);

namespace App\Domain\Herdr;

final readonly class HerdrSessionInspection
{
    /**
     * @param  list<HerdrLivePane>  $panes
     */
    public function __construct(
        public ?string $version,
        public ?int $protocol,
        public bool $handoffSupported,
        public array $panes,
    ) {}

    public function hasLivePanes(): bool
    {
        return array_any($this->panes, static fn (HerdrLivePane $pane): bool => $pane->live);
    }
}
