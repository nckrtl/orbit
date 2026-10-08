<?php

declare(strict_types=1);

namespace App\Actions\T3;

use App\Models\T3Peer;
use App\Models\T3Profile;

/** Binds a peer to one profile. A peer belongs to one profile, so this replaces any earlier binding. */
final readonly class BindT3PeerProfileAction
{
    public function execute(T3Peer $peer, T3Profile $profile): T3Peer
    {
        $peer->forceFill(['t3_profile_id' => $profile->id])->save();
        $peer->setRelation('profile', $profile);

        return $peer;
    }
}
