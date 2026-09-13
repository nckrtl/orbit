<?php

declare(strict_types=1);

namespace App\Actions\Herdr;

use App\Domain\Herdr\HerdrObserverPublisher;
use App\Models\HerdrSession;
use App\Models\Node;
use Throwable;

final readonly class CascadeNodeHerdrSessionsAction
{
    public function __construct(
        private HerdrObserverPublisher $observers,
        private RemoveHerdrSessionAction $remove,
    ) {}

    public function execute(Node $node, bool $forgetRecords): void
    {
        $sessions = HerdrSession::query()
            ->with(['node', 'process'])
            ->where('node_id', $node->id)
            ->orderBy('id')
            ->get();

        foreach ($sessions as $session) {
            if ($forgetRecords) {
                try {
                    $this->observers->retract($session, $node);
                } catch (Throwable) {
                }

                $session->update(['process_id' => null]);
                $session->delete();

                continue;
            }

            $this->remove->execute($session, acceptTermination: true);
        }
    }
}
