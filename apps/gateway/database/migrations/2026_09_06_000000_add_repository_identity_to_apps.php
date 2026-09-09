<?php

declare(strict_types=1);

use App\Domain\SourceControl\GitRepositoryIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        /** @var array<int, string> $identities */
        $identities = [];
        /** @var array<string, list<int>> $owners */
        $owners = [];

        foreach (DB::table('apps')->orderBy('id')->get(['id', 'repository_url']) as $app) {
            $id = (int) $app->id;
            $identity = GitRepositoryIdentity::derive((string) $app->repository_url);
            $identities[$id] = $identity;
            $owners[$identity][] = $id;
        }

        /** @var list<int> $conflictingIds */
        $conflictingIds = [];

        foreach ($owners as $ids) {
            if (count($ids) > 1) {
                array_push($conflictingIds, ...$ids);
            }
        }

        sort($conflictingIds, SORT_NUMERIC);

        if ($conflictingIds !== []) {
            throw new \RuntimeException(
                'Cannot enforce one App per repository while duplicate identities exist: '
                    .implode(', ', $conflictingIds),
            );
        }

        Schema::table('apps', static function (Blueprint $table): void {
            $table->text('repository_identity')->nullable();
        });

        foreach ($identities as $id => $identity) {
            DB::table('apps')->where('id', $id)->update(['repository_identity' => $identity]);
        }

        Schema::table('apps', static function (Blueprint $table): void {
            $table->text('repository_identity')->nullable(false)->change();
            $table->unique('repository_identity');
        });
    }

    public function down(): void
    {
        Schema::table('apps', static function (Blueprint $table): void {
            $table->dropUnique(['repository_identity']);
            $table->dropColumn('repository_identity');
        });
    }
};
