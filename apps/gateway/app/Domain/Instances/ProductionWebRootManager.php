<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

interface ProductionWebRootManager
{
    /**
     * Prepares the selected release of a production Instance for every web root that its Routes with a
     * web root serve: it checks each web root, links each application directory's `.env` to its stable
     * file, and grants Caddy access.
     */
    public function prepare(Instance $instance): void;
}
