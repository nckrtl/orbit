<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

interface DevelopmentSourceAccess
{
    /**
     * Lets Caddy read each Web root it serves from the Instance's checkout, or from a checkout
     * nested in it, and keeps the rest of those trees private. Other checkouts on the Node are not
     * walked. An Instance that Caddy does not serve yet gets nothing; its Route convergence grants
     * the access.
     */
    public function grant(Instance $instance): void;
}
