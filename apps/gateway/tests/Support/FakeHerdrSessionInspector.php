<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Herdr\HerdrLivePane;
use App\Domain\Herdr\HerdrSessionInspection;
use App\Domain\Herdr\HerdrSessionInspector;
use App\Models\HerdrSession;
use App\Models\Node;
use Throwable;

final class FakeHerdrSessionInspector implements HerdrSessionInspector
{
    /** @var list<HerdrLivePane> */
    public array $panes = [];

    public ?string $version = '0.9.0';

    public ?int $protocol = 22;

    public bool $handoffSupported = true;

    public int $handoffs = 0;

    public ?Throwable $failure = null;

    public function inspect(HerdrSession $session, Node $node): HerdrSessionInspection
    {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        return new HerdrSessionInspection(
            version: $this->version,
            protocol: $this->protocol,
            handoffSupported: $this->handoffSupported,
            panes: $this->panes,
        );
    }

    public function handoff(HerdrSession $session, Node $node): void
    {
        $this->handoffs++;
    }
}
