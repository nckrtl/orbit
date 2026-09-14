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
        Schema::create('app_instance_deploy_steps', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_instance_id')->constrained()->cascadeOnDelete();
            $table->string('name', 63);
            $table->string('phase', 32);
            $table->text('command');
            $table->unsignedInteger('timeout_seconds');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['app_instance_id', 'name']);
            $table->unique(['app_instance_id', 'phase', 'position']);
        });

        $instances = DB::table('app_instances')->select(['id', 'deployment_steps'])->get();

        foreach ($instances as $instance) {
            $steps = $this->decodeSteps($instance->deployment_steps);
            $positions = ['before_activation' => 0, 'after_activation' => 0];

            foreach ($steps as $step) {
                $phase = $step['phase'];
                DB::table('app_instance_deploy_steps')->insert([
                    'app_instance_id' => $instance->id,
                    'name' => $step['name'],
                    'phase' => $phase,
                    'command' => $step['command'],
                    'timeout_seconds' => $step['timeout_seconds'],
                    'position' => $positions[$phase],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $positions[$phase]++;
            }
        }

        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->dropColumn('deployment_steps');
        });
    }

    public function down(): void
    {
        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->json('deployment_steps')->nullable()->after('deployment_branch');
        });

        $grouped = [];

        foreach (DB::table('app_instance_deploy_steps')->orderBy('id')->get() as $row) {
            $grouped[$row->app_instance_id][$row->phase][$row->position] = [
                'name' => $row->name,
                'phase' => $row->phase,
                'command' => $row->command,
                'timeout_seconds' => $row->timeout_seconds,
            ];
        }

        foreach ($grouped as $instanceId => $phases) {
            $steps = [];

            foreach (['before_activation', 'after_activation'] as $phase) {
                $ordered = $phases[$phase] ?? [];
                ksort($ordered);
                array_push($steps, ...array_values($ordered));
            }

            DB::table('app_instances')->where('id', $instanceId)->update([
                'deployment_steps' => json_encode($steps, JSON_THROW_ON_ERROR),
            ]);
        }

        Schema::dropIfExists('app_instance_deploy_steps');
    }

    /**
     * @return list<array{name: string, phase: string, command: string, timeout_seconds: int}>
     */
    private function decodeSteps(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = is_string($value)
            ? json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR)
            : $value;

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return [];
        }

        $steps = [];

        foreach ($decoded as $step) {
            if (
                ! is_array($step)
                || ! is_string($step['name'] ?? null)
                || ! is_string($step['phase'] ?? null)
                || ! is_string($step['command'] ?? null)
                || ! is_int($step['timeout_seconds'] ?? null)
            ) {
                continue;
            }

            $steps[] = [
                'name' => $step['name'],
                'phase' => $step['phase'],
                'command' => $step['command'],
                'timeout_seconds' => $step['timeout_seconds'],
            ];
        }

        return $steps;
    }
};
