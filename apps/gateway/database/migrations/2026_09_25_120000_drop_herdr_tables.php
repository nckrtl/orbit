<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('herdr_observation_nonces');
        Schema::dropIfExists('jwks_keys');
        Schema::dropIfExists('herdr_sessions');
    }
};
