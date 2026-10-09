<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;
use App\Models\Route;

interface ProductionWebRootManager
{
    /**
     * Prepares the selected release of a production Instance for every web root that its Routes with a
     * web root serve: it checks each web root, links each application directory's `.env` to its stable
     * file, and grants Caddy access.
     */
    public function prepare(Instance $instance, ?Route $activating = null): void;

    /**
     * Checks, before a Route stores the web root, that the selected release holds it as a directory
     * without links and holds its application directory. It changes nothing on the Node.
     */
    public function assertServable(Instance $instance, string $webRoot): void;
}
