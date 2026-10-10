<?php

declare(strict_types=1);

namespace App\Actions\Conn;

use App\Models\ConnProfile;
use App\Models\ConnProfileBinding;
use App\Models\Node;

/** Binds a Node to one profile. A Node belongs to one profile, so this replaces any earlier binding. */
final readonly class BindConnNodeProfileAction
{
    public function execute(Node $node, ConnProfile $profile): ConnProfileBinding
    {
        return ConnProfileBinding::query()->updateOrCreate(
            ['node_id' => $node->id],
            ['conn_profile_id' => $profile->id],
        );
    }
}
