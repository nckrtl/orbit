<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_document_storages', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('endpoint')->nullable();
            $table->string('region', 63)->nullable();
            $table->string('bucket', 63)->nullable();
            $table->text('access_key_id')->nullable();
            $table->text('secret_access_key')->nullable();
            $table->timestamps();
        });

        DB::table('project_document_storages')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('project_document_storages');
    }
};
