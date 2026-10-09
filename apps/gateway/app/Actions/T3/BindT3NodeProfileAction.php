<?php

declare(strict_types=1);

namespace App\Actions\T3;

use App\Models\Node;
use App\Models\T3Profile;
use App\Models\T3ProfileBinding;

/** Binds a Node to one profile. A Node belongs to one profile, so this replaces any earlier binding. */
final readonly class BindT3NodeProfileAction
{
    public function execute(Node $node, T3Profile $profile): T3ProfileBinding
    {
        return T3ProfileBinding::query()->updateOrCreate(
            ['node_id' => $node->id],
            ['t3_profile_id' => $profile->id],
        );
    }
}
