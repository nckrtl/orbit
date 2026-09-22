<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Transfer\TransferArchiveAttempt;
use App\Domain\AppInstances\Transfer\TransferCheckout;
use App\Domain\AppInstances\Transfer\TransferDestinationAttempt;
use App\Domain\AppInstances\Transfer\TransferSourceAttempt;
use App\Domain\AppInstances\Transfer\TransferSourceCapture;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppInstances\RemoteAppInstanceTransferSource;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceTransfer;
use App\Models\Node;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('protects a source archive from creation under a permissive remote umask', function (): void {
    $fixture = new TransferArchiveNativeFixture;

    try {
        expect(filesize($fixture->attempt->archivePath('source')))->toBe(0)
            ->and(fileperms($fixture->attempt->archivePath('source')) & 0777)->toBe(0600)
            ->and(filesize($fixture->attempt->archivePath('destination')))->toBe(0)
            ->and(fileperms($fixture->attempt->archivePath('destination')) & 0777)->toBe(0600);
        $capture = $fixture->capture();

        expect(fileperms($capture->archiveIdentity) & 0777)->toBe(0600)
            ->and(fileperms(dirname($capture->archiveIdentity)) & 0777)->toBe(0700);
    } finally {
        $fixture->close();
    }
});

it('preserves a foreign source replacement during post-cutover cleanup', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->capture();
    $checkout = $fixture->instance->checkout_path;
    rename($checkout, $checkout.'.retained');
    mkdir($checkout, 0700);
    file_put_contents($checkout.'/foreign', 'foreign-source-sentinel');
    $fixture->transfer->update(['cutover_at' => now()]);

    try {
        $result = $fixture->source->cleanupSource($fixture->transfer);
        expect($result->sourcePlacementRemoved)->toBeFalse()
            ->and(file_get_contents($checkout.'/foreign'))->toBe('foreign-source-sentinel')
            ->and(is_file($checkout.'.retained/.env'))->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('preserves a common repository aliased to the recorded source path', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $checkout = $fixture->instance->checkout_path;
    $alias = $fixture->directory.'/common-alias';
    symlink($checkout, $alias);
    $fixture->transfer->update([
        'source_layout' => 'worktree', 'common_repository_path' => $alias, 'cutover_at' => now(),
    ]);

    try {
        $result = $fixture->source->cleanupSource($fixture->transfer);
        expect($result->sourcePlacementRemoved)->toBeFalse()
            ->and(is_file($checkout.'/.env'))->toBeTrue()
            ->and(is_dir($checkout.'/.git'))->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('deletes only the captured source and preserves parent siblings and later arrivals', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->capture();
    $checkout = $fixture->instance->checkout_path;
    $parent = dirname($checkout);
    mkdir($parent.'/sibling');
    file_put_contents($parent.'/sibling/foreign', 'sibling-sentinel');
    $fixture->transfer->update(['cutover_at' => now()]);

    try {
        expect($fixture->source->cleanupSource($fixture->transfer)->sourcePlacementRemoved)->toBeTrue()
            ->and(is_dir($checkout))->toBeFalse()
            ->and(file_get_contents($parent.'/sibling/foreign'))->toBe('sibling-sentinel');
        mkdir($checkout);
        file_put_contents($checkout.'/foreign', 'later-sentinel');
        expect($fixture->source->cleanupSource($fixture->transfer)->sourcePlacementRemoved)->toBeTrue()
            ->and(file_get_contents($checkout.'/foreign'))->toBe('later-sentinel');
        expect(fn () => $fixture->capture())->toThrow(ResourceOperationException::class);
    } finally {
        $fixture->close();
    }
});

it('preserves common repository sibling worktree and local refs when deleting a captured worktree', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $commonCheckout = $fixture->instance->checkout_path;
    $worktree = $fixture->directory.'/linked-source';
    $sibling = $fixture->directory.'/linked-sibling';
    $fixture->git(['worktree', 'add', '--quiet', '-b', 'preview', $worktree], $commonCheckout);
    $fixture->git(['worktree', 'add', '--quiet', '-b', 'sibling', $sibling], $commonCheckout);
    file_put_contents($sibling.'/untracked', 'sibling-sentinel');
    $refs = $fixture->git(['show-ref'], $commonCheckout);
    $sharedSnapshot = static function () use ($commonCheckout): array {
        $snapshot = [];
        foreach (File::allFiles($commonCheckout.'/.git') as $file) {
            $snapshot[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }
        ksort($snapshot);

        return $snapshot;
    };
    $administration = $sharedSnapshot();
    $fixture->instance->update(['checkout_path' => $worktree, 'source_layout' => 'worktree']);
    $fixture->capture();
    $fixture->transfer->update(['cutover_at' => now()]);

    try {
        expect($fixture->source->cleanupSource($fixture->transfer)->sourcePlacementRemoved)->toBeTrue()
            ->and(is_dir($worktree))->toBeFalse()
            ->and(is_dir($commonCheckout.'/.git'))->toBeTrue()
            ->and(file_get_contents($sibling.'/untracked'))->toBe('sibling-sentinel')
            ->and($fixture->git(['show-ref'], $commonCheckout))->toBe($refs)
            ->and(file_get_contents($commonCheckout.'/.git/worktrees/linked-source/gitdir'))->toBe($worktree."/.git\n")
            ->and($sharedSnapshot())->toBe($administration);
    } finally {
        $fixture->close();
    }
});

it('refuses captured source deletion after its parent changes', function (bool $symlink): void {
    $fixture = new TransferArchiveNativeFixture;
    $parent = dirname($fixture->instance->checkout_path).'/projects';
    mkdir($parent);
    rename($fixture->instance->checkout_path, $parent.'/source');
    $fixture->instance->update(['checkout_path' => $parent.'/source']);
    $fixture->capture();
    rename($parent, $parent.'.retained');
    $foreign = $fixture->directory.'/foreign-parent';
    mkdir($foreign);
    if ($symlink) {
        symlink($foreign, $parent);
    } else {
        mkdir($parent);
    }
    mkdir($parent.'/source');
    file_put_contents($parent.'/source/foreign', 'foreign-sentinel');
    $fixture->transfer->update(['cutover_at' => now()]);

    try {
        expect($fixture->source->cleanupSource($fixture->transfer)->sourcePlacementRemoved)->toBeFalse()
            ->and(file_get_contents($parent.'/source/foreign'))->toBe('foreign-sentinel')
            ->and(is_file($parent.'.retained/source/.env'))->toBeTrue();
    } finally {
        $fixture->close();
    }
})->with(['symlink' => true, 'replacement' => false]);

it('refuses a source cleanup claim occupied by a foreign directory', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->capture();
    $claim = dirname($fixture->instance->checkout_path).'/.orbit-transfer-sources/'.$fixture->sourceAttempt->id.'.claimed';
    mkdir($claim, 0700);
    file_put_contents($claim.'/foreign', 'claim-sentinel');
    $fixture->transfer->update(['cutover_at' => now()]);

    try {
        expect($fixture->source->cleanupSource($fixture->transfer)->sourcePlacementRemoved)->toBeFalse()
            ->and(file_get_contents($claim.'/foreign'))->toBe('claim-sentinel')
            ->and(is_file($fixture->instance->checkout_path.'/.env'))->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('restores a foreign source swapped between validation and atomic cleanup claim', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->capture();
    $fixture->transfer->update(['cutover_at' => now()]);
    $fixture->ssh->programReplacements = [
        'self.rename(parent, self.name, scope, self.claim_name)' => implode("\n", [
            'os.rename(self.name, self.name + ".retained", src_dir_fd=parent, dst_dir_fd=parent)',
            '            os.mkdir(self.name, 0o755, dir_fd=parent)',
            '            foreign = os.open(self.name + "/foreign", os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600, dir_fd=parent)',
            '            os.write(foreign, b"foreign-sentinel")',
            '            os.close(foreign)',
            '            self.rename(parent, self.name, scope, self.claim_name)',
        ]),
    ];

    try {
        expect($fixture->source->cleanupSource($fixture->transfer)->sourcePlacementRemoved)->toBeFalse()
            ->and(file_get_contents($fixture->instance->checkout_path.'/foreign'))->toBe('foreign-sentinel')
            ->and(is_file($fixture->instance->checkout_path.'.retained/.env'))->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('does not acknowledge unexplained disappearance of the captured source', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->capture();
    rename($fixture->instance->checkout_path, $fixture->instance->checkout_path.'.retained');
    $fixture->transfer->update(['cutover_at' => now()]);

    try {
        expect($fixture->source->cleanupSource($fixture->transfer)->sourcePlacementRemoved)->toBeFalse()
            ->and(is_file($fixture->instance->checkout_path.'.retained/.env'))->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('resumes source cleanup after claim deletion and metadata interruptions', function (string $window): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->capture();
    $fixture->transfer->update(['cutover_at' => now()]);
    $fixture->ssh->programReplacements = match ($window) {
        'after claim' => ['moved = self.metadata(scope, self.claim_name)' => 'raise OSError("claim interrupted")'],
        'during deletion' => ['os.unlink(name, dir_fd=directory)' => "os.unlink(name, dir_fd=directory)\n                raise OSError(\"deletion interrupted\")"],
        'after deletion' => ['os.fsync(scope)' => 'raise OSError("cleanup acknowledgment lost")'],
        'torn journal' => ['written = os.pwrite(descriptor, frame[position:], offset + position)' => implode("\n", [
            'written = os.pwrite(descriptor, frame[position:position + 24] if self.state["phase"] == "cleaned" else frame[position:], offset + position)',
            '            if self.state["phase"] == "cleaned":',
            '                os.fsync(descriptor)',
            '                os.kill(os.getpid(), 9)',
        ])],
    };

    try {
        expect($fixture->source->cleanupSource($fixture->transfer)->sourcePlacementRemoved)->toBeFalse();
        $fixture->ssh->programReplacements = [];
        expect($fixture->source->cleanupSource($fixture->transfer)->sourcePlacementRemoved)->toBeTrue()
            ->and($fixture->source->cleanupSource($fixture->transfer)->sourcePlacementRemoved)->toBeTrue()
            ->and(is_dir($fixture->instance->checkout_path))->toBeFalse();
    } finally {
        $fixture->close();
    }
})->with(['after claim', 'during deletion', 'after deletion', 'torn journal']);

it('recovers the same observed source receipt after its creation acknowledgment is lost', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->ssh->after = fn (SshConnection $connection, RemoteCommand $command, CommandResult $result): CommandResult => $command->arguments[array_key_last($command->arguments)] === 'observe' ? new CommandResult(1, '', '', 1, false) : $result;

    try {
        expect(fn () => $fixture->prepareSource())->toThrow(ResourceOperationException::class);
        $attemptId = $fixture->sourceAttempt->id;
        expect($fixture->sourceAttempt->phase)->toBe('acquiring');
        $fixture->ssh->after = null;
        $fixture->prepareSource();
        expect($fixture->sourceAttempt->id)->toBe($attemptId)
            ->and($fixture->sourceAttempt->phase)->toBe('owned');
        $fixture->capture();
        $fixture->transfer->update(['cutover_at' => now()]);
        expect($fixture->source->cleanupSource($fixture->transfer)->sourcePlacementRemoved)->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('refuses capture after the observed source has been replaced', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->prepareSource();
    $checkout = $fixture->instance->checkout_path;
    rename($checkout, $checkout.'.retained');
    mkdir($checkout);
    file_put_contents($checkout.'/foreign', 'foreign-sentinel');

    try {
        expect(fn () => $fixture->capture())->toThrow(ResourceOperationException::class);
        expect(filesize($fixture->attempt->archivePath('source')))->toBe(0)
            ->and(file_get_contents($checkout.'/foreign'))->toBe('foreign-sentinel')
            ->and(is_file($checkout.'.retained/.env'))->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('acknowledges lost source cleanup completion without deleting a later arrival', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->capture();
    $fixture->transfer->update(['cutover_at' => now()]);
    $fixture->ssh->after = fn (SshConnection $connection, RemoteCommand $command, CommandResult $result): CommandResult => new CommandResult(1, '', '', 1, false);

    try {
        expect($fixture->source->cleanupSource($fixture->transfer)->sourcePlacementRemoved)->toBeFalse()
            ->and(is_dir($fixture->instance->checkout_path))->toBeFalse();
        mkdir($fixture->instance->checkout_path);
        file_put_contents($fixture->instance->checkout_path.'/foreign', 'later-sentinel');
        $fixture->ssh->after = null;
        expect($fixture->source->cleanupSource($fixture->transfer)->sourcePlacementRemoved)->toBeTrue()
            ->and(file_get_contents($fixture->instance->checkout_path.'/foreign'))->toBe('later-sentinel');
    } finally {
        $fixture->close();
    }
});

it('preserves the source worktree when its captured common repository is replaced', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $commonCheckout = $fixture->instance->checkout_path;
    $worktree = $fixture->directory.'/linked-source';
    $fixture->git(['worktree', 'add', '--quiet', '-b', 'preview', $worktree], $commonCheckout);
    $fixture->instance->update(['checkout_path' => $worktree, 'source_layout' => 'worktree']);
    $fixture->capture();
    $common = $fixture->sourceAttempt->receipt['common_path'];
    rename($common, $common.'.retained');
    mkdir($common);
    file_put_contents($common.'/foreign', 'common-sentinel');
    $fixture->transfer->update(['cutover_at' => now()]);

    try {
        expect($fixture->source->cleanupSource($fixture->transfer)->sourcePlacementRemoved)->toBeFalse()
            ->and(file_get_contents($common.'/foreign'))->toBe('common-sentinel')
            ->and(is_file($worktree.'/.git'))->toBeTrue()
            ->and(is_file($common.'.retained/HEAD'))->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('never dispatches source deletion before cutover or without captured evidence', function (bool $legacy): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->capture();
    if ($legacy) {
        $fixture->transfer->update(['source_attempt' => null, 'cutover_at' => now()]);
    }
    $calls = count($fixture->ssh->calls);

    try {
        expect($fixture->source->cleanupSource($fixture->transfer)->sourcePlacementRemoved)->toBeFalse()
            ->and(count($fixture->ssh->calls))->toBe($calls)
            ->and(is_file($fixture->instance->checkout_path.'/.env'))->toBeTrue();
    } finally {
        $fixture->close();
    }
})->with(['pre-cutover' => false, 'legacy' => true]);

it('refuses source admission when a linked common directory is nested or aliased', function (bool $symlink): void {
    $fixture = new TransferArchiveNativeFixture;
    $checkout = $fixture->instance->checkout_path;
    $common = $checkout.'/shared.git';
    rename($checkout.'/.git', $common);
    if ($symlink) {
        symlink($common, $checkout.'/common-alias');
    }
    file_put_contents($checkout.'/.git', 'gitdir: '.($symlink ? $checkout.'/common-alias' : $common)."\n");
    $fixture->instance->update(['source_layout' => 'worktree']);

    try {
        expect(fn () => $fixture->capture())->toThrow(ResourceOperationException::class);
        expect(filesize($fixture->attempt->archivePath('source')))->toBe(0)
            ->and(is_file($checkout.'/.env'))->toBeTrue()
            ->and(is_file($common.'/HEAD'))->toBeTrue();
    } finally {
        $fixture->close();
    }
})->with(['nested common directory' => false, 'symlinked common directory' => true]);

it('preserves a foreign destination when capture failed before destination materialization', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    mkdir($fixture->destinationPath, 0755, recursive: true);
    file_put_contents($fixture->destinationPath.'/foreign', 'foreign-destination-sentinel');
    $fixture->ssh->programReplacements = ['os.fsync(archive)' => 'raise OSError("capture failed")'];

    try {
        expect(fn () => $fixture->materialize())->toThrow(ResourceOperationException::class);
        $calls = count($fixture->ssh->calls);
        $fixture->source->discardDestination($fixture->destinationAttempt);

        expect(file_get_contents($fixture->destinationPath.'/foreign'))->toBe('foreign-destination-sentinel')
            ->and($fixture->destinationAttempt->phase)->toBe('reserved')
            ->and(count($fixture->ssh->calls))->toBe($calls);
    } finally {
        $fixture->close();
    }
});

it('preserves a foreign replacement of a materialized destination during recovery', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->materialize();
    rename($fixture->destinationPath, $fixture->destinationPath.'.retained');
    mkdir($fixture->destinationPath, 0755);
    file_put_contents($fixture->destinationPath.'/foreign', 'foreign-destination-sentinel');

    try {
        expect(fn () => $fixture->source->discardDestination($fixture->destinationAttempt))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_destination_cleanup_incomplete'));

        expect(is_file($fixture->destinationPath.'/foreign'))->toBeTrue()
            ->and(is_file($fixture->destinationPath.'.retained/.env'))->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('cleans only the owned destination and preserves its parent siblings and later arrivals', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->materialize();
    $parent = dirname($fixture->destinationPath);
    mkdir($parent.'/sibling');
    file_put_contents($parent.'/sibling/foreign', 'sibling-sentinel');

    try {
        $fixture->source->discardDestination($fixture->destinationAttempt);
        expect(is_dir($fixture->destinationPath))->toBeFalse()
            ->and(is_dir($parent))->toBeTrue()
            ->and(file_get_contents($parent.'/sibling/foreign'))->toBe('sibling-sentinel');
        mkdir($fixture->destinationPath);
        file_put_contents($fixture->destinationPath.'/foreign', 'later-sentinel');
        $fixture->source->discardDestination($fixture->destinationAttempt);
        expect(file_get_contents($fixture->destinationPath.'/foreign'))->toBe('later-sentinel');
        expect(fn () => $fixture->source->prepareDestination($fixture->destinationIntent()))->toThrow(ResourceOperationException::class);
    } finally {
        $fixture->close();
    }
});

it('preserves a foreign destination that wins placement while deleting only its owned empty stage', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    mkdir($fixture->destinationPath, 0755, recursive: true);
    file_put_contents($fixture->destinationPath.'/foreign', 'foreign-destination-sentinel');

    try {
        expect(fn () => $fixture->prepareDestination())->toThrow(ResourceOperationException::class);
        $fixture->source->discardDestination($fixture->destinationAttempt);

        expect(file_get_contents($fixture->destinationPath.'/foreign'))->toBe('foreign-destination-sentinel')
            ->and(array_values(array_diff(scandir($fixture->destinationScope()), ['.', '..'])))->toBe([]);
    } finally {
        $fixture->close();
    }
});

it('refuses a private destination stage changed after its first observation before opening', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->ssh->programReplacements = [
        'stage = self.track(os.open(self.stage_name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=scope))' => implode("\n", [
            'os.rename(self.stage_name, self.stage_name + ".retained", src_dir_fd=scope, dst_dir_fd=scope)',
            '        os.mkdir(self.stage_name, 0o755, dir_fd=scope)',
            '        foreign = os.open(self.stage_name + "/foreign", os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600, dir_fd=scope)',
            '        os.write(foreign, b"foreign-sentinel")',
            '        os.close(foreign)',
            '        stage = self.track(os.open(self.stage_name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=scope))',
        ]),
    ];

    try {
        expect(fn () => $fixture->prepareDestination())->toThrow(ResourceOperationException::class);
        $stage = $fixture->destinationScope().'/'.$fixture->destinationAttempt->id.'.stage';
        expect(is_dir($fixture->destinationPath))->toBeFalse()
            ->and(file_get_contents($stage.'/foreign'))->toBe('foreign-sentinel')
            ->and(is_dir($stage.'.retained'))->toBeTrue();
        $fixture->ssh->programReplacements = [];
        expect(fn () => $fixture->source->discardDestination($fixture->destinationAttempt))->toThrow(ResourceOperationException::class);
        expect(file_get_contents($stage.'/foreign'))->toBe('foreign-sentinel');
    } finally {
        $fixture->close();
    }
});

it('restores a foreign stage swapped after its receipt and before atomic destination placement', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->ssh->programReplacements = [
        'self.rename(scope, self.stage_name, parent, self.name)' => implode("\n", [
            'os.rename(self.stage_name, self.stage_name + ".retained", src_dir_fd=scope, dst_dir_fd=scope)',
            '        os.mkdir(self.stage_name, 0o755, dir_fd=scope)',
            '        foreign = os.open(self.stage_name + "/foreign", os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600, dir_fd=scope)',
            '        os.write(foreign, b"foreign-sentinel")',
            '        os.close(foreign)',
            '        self.rename(scope, self.stage_name, parent, self.name)',
        ]),
    ];

    try {
        expect(fn () => $fixture->prepareDestination())->toThrow(ResourceOperationException::class);
        $stage = $fixture->destinationScope().'/'.$fixture->destinationAttempt->id.'.stage';
        expect(is_dir($fixture->destinationPath))->toBeFalse()
            ->and(file_get_contents($stage.'/foreign'))->toBe('foreign-sentinel')
            ->and(is_dir($stage.'.retained'))->toBeTrue();
        $fixture->ssh->programReplacements = [];
        expect(fn () => $fixture->source->discardDestination($fixture->destinationAttempt))->toThrow(ResourceOperationException::class);
        expect(file_get_contents($stage.'/foreign'))->toBe('foreign-sentinel');
    } finally {
        $fixture->close();
    }
});

it('removes its partially extracted destination after materialization fails', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->transport->failure = 'partial extraction';

    try {
        expect(fn () => $fixture->materialize())->toThrow(ResourceOperationException::class);
        expect(count(scandir($fixture->destinationPath)))->toBeGreaterThan(2);
        $fixture->source->discardDestination($fixture->destinationAttempt);

        expect(is_dir($fixture->destinationPath))->toBeFalse()
            ->and(is_file($fixture->instance->checkout_path.'/.env'))->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('refuses changed destination parents without deleting a retained checkout or foreign contents', function (bool $symlink): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->materialize();
    $parent = dirname($fixture->destinationPath);
    rename($parent, $parent.'.retained');
    if ($symlink) {
        symlink($parent.'.retained', $parent);
    } else {
        mkdir($fixture->destinationPath, 0755, recursive: true);
        file_put_contents($fixture->destinationPath.'/foreign', 'foreign-destination-sentinel');
    }

    try {
        expect(fn () => $fixture->source->discardDestination($fixture->destinationAttempt))->toThrow(ResourceOperationException::class);
        expect(file_get_contents($parent.'.retained/web/.env'))->toBe("KEY=archive-secret-sentinel\n");
        if (! $symlink) {
            expect(file_get_contents($fixture->destinationPath.'/foreign'))->toBe('foreign-destination-sentinel');
        }
    } finally {
        $fixture->close();
    }
})->with(['symlink' => true, 'replacement' => false]);

it('preserves a foreign destination quarantine collision', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->materialize();
    $claim = $fixture->destinationScope().'/'.$fixture->destinationAttempt->id.'.claimed';
    mkdir($claim, 0700);
    file_put_contents($claim.'/foreign', 'foreign-claim-sentinel');

    try {
        expect(fn () => $fixture->source->discardDestination($fixture->destinationAttempt))->toThrow(ResourceOperationException::class);
        expect(file_get_contents($claim.'/foreign'))->toBe('foreign-claim-sentinel')
            ->and(is_file($fixture->destinationPath.'/.env'))->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('preserves a foreign destination swapped between ownership validation and atomic claim', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->materialize();
    $fixture->ssh->programReplacements = [
        'self.rename(origin_parent, origin_name, scope, self.claim_name)' => implode("\n", [
            'os.rename(origin_name, origin_name + ".retained", src_dir_fd=origin_parent, dst_dir_fd=origin_parent)',
            '            os.mkdir(origin_name, 0o755, dir_fd=origin_parent)',
            '            foreign = os.open(origin_name + "/foreign", os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600, dir_fd=origin_parent)',
            '            os.write(foreign, b"foreign-sentinel")',
            '            os.close(foreign)',
            '            self.rename(origin_parent, origin_name, scope, self.claim_name)',
        ]),
    ];

    try {
        expect(fn () => $fixture->source->discardDestination($fixture->destinationAttempt))->toThrow(ResourceOperationException::class);
        expect(file_get_contents($fixture->destinationPath.'/foreign'))->toBe('foreign-sentinel')
            ->and(is_file($fixture->destinationPath.'.retained/.env'))->toBeTrue()
            ->and(is_dir($fixture->destinationScope().'/'.$fixture->destinationAttempt->id.'.claimed'))->toBeFalse();
    } finally {
        $fixture->close();
    }
});

it('refuses extraction into a replaced destination before writing any archive contents', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->ssh->before = static function (SshConnection $connection, RemoteCommand $command) use ($fixture): void {
        if ($command->arguments[3] === 'materialize') {
            rename($fixture->destinationPath, $fixture->destinationPath.'.retained');
            mkdir($fixture->destinationPath, 0755);
            file_put_contents($fixture->destinationPath.'/foreign', 'foreign-sentinel');
        }
    };

    try {
        expect(fn () => $fixture->materialize())->toThrow(ResourceOperationException::class);
        expect(file_get_contents($fixture->destinationPath.'/foreign'))->toBe('foreign-sentinel')
            ->and(array_values(array_diff(scandir($fixture->destinationPath), ['.', '..'])))->toBe(['foreign'])
            ->and(array_values(array_diff(scandir($fixture->destinationPath.'.retained'), ['.', '..'])))->toBe([]);
        expect(fn () => $fixture->source->discardDestination($fixture->destinationAttempt))->toThrow(ResourceOperationException::class);
    } finally {
        $fixture->close();
    }
});

it('refuses destination cleanup when the protected receipt root changes', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->materialize();
    $root = $fixture->destinationAttempt->privateRoot;
    rename($root, $root.'.retained');
    mkdir($root, 0700);
    file_put_contents($root.'/foreign', 'foreign-sentinel');

    try {
        expect(fn () => $fixture->source->discardDestination($fixture->destinationAttempt))->toThrow(ResourceOperationException::class);
        expect(file_get_contents($root.'/foreign'))->toBe('foreign-sentinel')
            ->and(is_file($fixture->destinationPath.'/.env'))->toBeTrue()
            ->and(is_file($root.'.retained/'.$fixture->destinationAttempt->id.'.json'))->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('recovers exact empty destination ownership after interrupted placement', function (string $window): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->ssh->programReplacements = match ($window) {
        'before placement' => ['self.rename(scope, self.stage_name, parent, self.name)' => 'raise OSError("placement interrupted")'],
        'after placement' => ['self.state["phase"] = "owned"' => 'raise OSError("placement interrupted")'],
    };

    try {
        expect(fn () => $fixture->prepareDestination())->toThrow(ResourceOperationException::class);
        expect($fixture->destinationAttempt->phase)->toBe('acquiring')
            ->and($fixture->destinationAttempt->receipt)->toBeNull();
        $fixture->ssh->programReplacements = [];
        $fixture->source->discardDestination($fixture->destinationAttempt);

        expect(is_dir($fixture->destinationPath))->toBeFalse()
            ->and(array_values(array_diff(scandir($fixture->destinationScope()), ['.', '..'])))->toBe([]);
        expect(fn () => $fixture->source->prepareDestination($fixture->destinationAttempt))->toThrow(ResourceOperationException::class);
    } finally {
        $fixture->close();
    }
})->with(['before placement', 'after placement']);

it('recovers a created destination after its acknowledgement is lost before receipt persistence', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->ssh->after = static function (SshConnection $connection, RemoteCommand $command, CommandResult $result): CommandResult {
        if ($command->arguments[3] === 'create') {
            throw new RuntimeException('destination-secret-sentinel');
        }

        return $result;
    };

    try {
        expect(fn () => $fixture->prepareDestination())->toThrow(ResourceOperationException::class);
        expect(is_dir($fixture->destinationPath))->toBeTrue()
            ->and($fixture->transfer->refresh()->destination_attempt['receipt'])->toBeNull();
        $fixture->source->discardDestination($fixture->destinationAttempt);
        expect(is_dir($fixture->destinationPath))->toBeFalse();
    } finally {
        $fixture->close();
    }
});

it('settles a never-started acquisition without creating the destination and refuses late creation', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->destinationAttempt = $fixture->destinationAttempt->acquiring();
    $fixture->transfer->update(['destination_attempt' => $fixture->destinationAttempt->toArray()]);

    try {
        $fixture->source->discardDestination($fixture->destinationAttempt);
        expect(is_dir(dirname($fixture->destinationPath)))->toBeFalse();
        expect(fn () => $fixture->source->prepareDestination($fixture->destinationAttempt))->toThrow(ResourceOperationException::class);
        expect(is_dir($fixture->destinationPath))->toBeFalse();
    } finally {
        $fixture->close();
    }
});

it('refuses delayed materialization after the destination cleanup tombstone is durable', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $capture = $fixture->capture();
    $fixture->prepareDestination();

    try {
        $fixture->source->discardDestination($fixture->destinationAttempt);
        expect(fn () => $fixture->source->materialize(
            $capture, $fixture->destination, StoragePath::parse($fixture->destinationPath), $fixture->attempt, $fixture->destinationAttempt,
        ))->toThrow(ResourceOperationException::class);
        expect(is_dir($fixture->destinationPath))->toBeFalse()
            ->and($fixture->source->cleanupArchives($fixture->attempt))->toBe([]);
    } finally {
        $fixture->close();
    }
});

it('retains destination ownership when its checkout disappears without a cleanup claim', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->materialize();
    rename($fixture->destinationPath, $fixture->destinationPath.'.retained');

    try {
        expect(fn () => $fixture->source->discardDestination($fixture->destinationAttempt))->toThrow(ResourceOperationException::class);
        expect(is_file($fixture->destinationPath.'.retained/.env'))->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('resumes owned destination deletion after a native cleanup interruption', function (string $window): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->materialize();
    $fixture->ssh->programReplacements = match ($window) {
        'after claim' => ['moved = self.metadata(scope, self.claim_name)' => 'raise OSError("claim interrupted")'],
        'during deletion' => ['os.unlink(name, dir_fd=directory)' => "os.unlink(name, dir_fd=directory)\n                raise OSError(\"deletion interrupted\")"],
        'after deletion' => ['os.fsync(scope)' => 'raise OSError("receipt interrupted")'],
    };

    try {
        expect(fn () => $fixture->source->discardDestination($fixture->destinationAttempt))->toThrow(ResourceOperationException::class);
        $fixture->ssh->programReplacements = [];
        $fixture->source->discardDestination($fixture->destinationAttempt);
        $fixture->source->discardDestination($fixture->destinationAttempt);

        expect(is_dir($fixture->destinationPath))->toBeFalse()
            ->and(array_values(array_diff(scandir($fixture->destinationScope()), ['.', '..'])))->toBe([]);
    } finally {
        $fixture->close();
    }
})->with(['after claim', 'during deletion', 'after deletion']);

it('recovers destination cleanup after a killed metadata update without growing its journal', function (bool $partial): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->materialize();
    $journal = $fixture->destinationAttempt->privateRoot.'/'.$fixture->destinationAttempt->id.'.json';
    $before = stat($journal);
    $exitCode = null;
    $fixture->ssh->after = function (SshConnection $connection, RemoteCommand $command, CommandResult $result) use (&$exitCode): CommandResult {
        $exitCode = $result->exitCode;

        return $result;
    };
    $fixture->ssh->programReplacements = [
        'written = os.pwrite(descriptor, frame[position:], offset + position)' => implode("\n", [
            'written = os.pwrite(descriptor, frame[position:position + 24] if '.($partial ? 'True' : 'False').' and self.state["phase"] == "cleaned" else frame[position:], offset + position)',
            '            if self.state["phase"] == "cleaned":',
            '                os.fsync(descriptor)',
            '                os.kill(os.getpid(), 9)',
        ]),
    ];

    try {
        expect(fn () => $fixture->source->discardDestination($fixture->destinationAttempt))->toThrow(ResourceOperationException::class);
        expect($exitCode)->toBe(137)
            ->and(is_dir($fixture->destinationPath))->toBeFalse();
        mkdir($fixture->destinationPath);
        file_put_contents($fixture->destinationPath.'/foreign', 'later-sentinel');
        $fixture->ssh->programReplacements = [];
        $fixture->source->discardDestination($fixture->destinationAttempt);
        $fixture->source->discardDestination($fixture->destinationAttempt);
        clearstatcache(true, $journal);
        $after = stat($journal);
        expect(file_get_contents($fixture->destinationPath.'/foreign'))->toBe('later-sentinel')
            ->and($after['ino'])->toBe($before['ino'])
            ->and($after['size'])->toBe(32768)
            ->and(file_exists($fixture->destinationAttempt->privateRoot.'/'.$fixture->destinationAttempt->id.'.next'))->toBeFalse();
    } finally {
        $fixture->close();
    }
})->with(['partial write' => true, 'complete write' => false]);

it('refuses destination cleanup when its metadata journal cannot prove a committed receipt', function (string $fault): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->materialize();
    $journal = $fixture->destinationAttempt->privateRoot.'/'.$fixture->destinationAttempt->id.'.json';
    match ($fault) {
        'legacy format' => file_put_contents($journal, '{"phase":"owned"}'),
        'both torn' => file_put_contents($journal, str_repeat("\0", 32768)),
        'oversized' => file_put_contents($journal, str_repeat('x', 32769)),
        'wrong mode' => chmod($journal, 0644),
        'hard link' => link($journal, $journal.'.retained'),
    };

    try {
        expect(fn () => $fixture->source->discardDestination($fixture->destinationAttempt))->toThrow(ResourceOperationException::class);
        expect(file_get_contents($fixture->destinationPath.'/.env'))->toBe("KEY=archive-secret-sentinel\n")
            ->and(is_dir($fixture->destinationScope().'/'.$fixture->destinationAttempt->id.'.claimed'))->toBeFalse();
    } finally {
        $fixture->close();
    }
})->with(['legacy format', 'both torn', 'oversized', 'wrong mode', 'hard link']);

it('does not infer destination ownership from an interrupted initial metadata record', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->ssh->programReplacements = [
        'written = os.pwrite(descriptor, frame[position:], offset + position)' => implode("\n", [
            'written = os.pwrite(descriptor, frame[position:position + 24], offset + position)',
            '            os.fsync(descriptor)',
            '            os.kill(os.getpid(), 9)',
        ]),
    ];

    try {
        expect(fn () => $fixture->prepareDestination())->toThrow(ResourceOperationException::class);
        mkdir($fixture->destinationPath, 0755, recursive: true);
        file_put_contents($fixture->destinationPath.'/foreign', 'foreign-sentinel');
        $fixture->ssh->programReplacements = [];
        expect(fn () => $fixture->source->discardDestination($fixture->destinationAttempt))->toThrow(ResourceOperationException::class);
        expect(file_get_contents($fixture->destinationPath.'/foreign'))->toBe('foreign-sentinel');
    } finally {
        $fixture->close();
    }
});

it('confirms lost destination cleanup acknowledgements without deleting a later arrival', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->materialize();
    $fixture->ssh->after = static function (SshConnection $connection, RemoteCommand $command, CommandResult $result): CommandResult {
        if ($command->arguments[3] === 'cleanup') {
            throw new RuntimeException('destination-secret-sentinel');
        }

        return $result;
    };

    try {
        expect(fn () => $fixture->source->discardDestination($fixture->destinationAttempt))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->getMessage())->not->toContain('destination-secret-sentinel'));
        expect(is_dir($fixture->destinationPath))->toBeFalse();
        mkdir($fixture->destinationPath);
        file_put_contents($fixture->destinationPath.'/foreign', 'later-sentinel');
        $fixture->ssh->after = null;
        $fixture->source->discardDestination($fixture->destinationAttempt);

        expect(file_get_contents($fixture->destinationPath.'/foreign'))->toBe('later-sentinel');
    } finally {
        $fixture->close();
    }
});

it('removes both remote archive payloads after native materialization without changing source content', function (): void {
    $fixture = new TransferArchiveNativeFixture;

    try {
        $sourceArchive = $fixture->attempt->archivePath('source');
        $checkout = $fixture->materialize();

        expect(file_get_contents($checkout->path.'/.env'))->toBe("KEY=archive-secret-sentinel\n")
            ->and(file_get_contents($fixture->instance->checkout_path.'/.env'))->toBe("KEY=archive-secret-sentinel\n")
            ->and(is_file($sourceArchive))->toBeFalse()
            ->and(is_file($fixture->transport->uploadedPath))->toBeFalse();
        expect($fixture->transport->invocations)->toHaveCount(2);
        expect($fixture->transport->targetModes)->toBe([0600, 0600]);

        foreach (['source', 'destination'] as $side) {
            $root = $fixture->attempt->location($side)['private_root'];
            expect(array_values(array_diff(scandir($root), ['.', '..'])))->toBe([
                $fixture->attempt->id.'.json', $fixture->attempt->id.'.lock',
            ]);
            expect(file_get_contents($root.'/'.$fixture->attempt->id.'.json'))->not->toContain('archive-secret-sentinel');
        }

        foreach ($fixture->transport->invocations as $invocation) {
            expect(array_slice($invocation->arguments, 0, -2))->toBe([
                'scp', '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes',
                '-o', 'StrictHostKeyChecking=yes', '-i', '/transfer-test/key',
                '-o', 'UserKnownHostsFile=/transfer-test/known-hosts',
            ]);
        }
    } finally {
        $fixture->close();
    }
});

it('removes owned payloads and local stages across capture transport and extraction failures', function (string $failure): void {
    $fixture = new TransferArchiveNativeFixture;
    if ($failure === 'capture') {
        $fixture->ssh->programReplacements = ['os.fsync(archive)' => 'raise OSError("archive-secret-sentinel")'];
    } else {
        $fixture->transport->failure = $failure;
    }

    try {
        expect(fn () => $fixture->materialize())
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('instance.transfer_failed')
                    ->and($exception->getMessage())->not->toContain('archive-secret-sentinel')
                    ->and($exception->getPrevious())->toBeNull();
            });
        expect(is_dir(dirname($fixture->attempt->archivePath('source'))))->toBeFalse()
            ->and(is_dir(dirname($fixture->attempt->archivePath('destination'))))->toBeFalse();
        foreach ($fixture->transport->localStages as $path) {
            expect(is_file($path))->toBeFalse();
        }
        foreach ($fixture->transport->targetModes as $mode) {
            expect($mode)->toBe(0600);
        }
        expect(file_get_contents($fixture->instance->checkout_path.'/.env'))->toBe("KEY=archive-secret-sentinel\n");
    } finally {
        $fixture->close();
    }
})->with(['capture', 'download', 'download exception', 'download typed exception', 'upload', 'upload exception', 'upload typed exception', 'truncated upload', 'extraction']);

it('keeps a worktree bundle private and removes only its temporary archive payloads', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $worktree = $fixture->directory.'/linked';
    $fixture->git(['worktree', 'add', '--quiet', '-b', 'preview', $worktree], $fixture->instance->checkout_path);
    $fixture->instance->update(['checkout_path' => $worktree, 'source_layout' => 'worktree']);
    file_put_contents($worktree.'/.env', "KEY=archive-secret-sentinel\n");

    try {
        $capture = $fixture->capture();
        $bundle = dirname($capture->archiveIdentity).'/'.$fixture->attempt->id.'.bundle';

        expect(fileperms($bundle) & 0777)->toBe(0600)
            ->and(filesize($bundle))->toBeGreaterThan(0);
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe([])
            ->and(is_file($bundle))->toBeFalse()
            ->and(is_file($worktree.'/.git'))->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('keeps overlapping attempts unique without removing the other attempt', function (): void {
    $fixture = new TransferArchiveNativeFixture;

    try {
        $first = $fixture->capture();
        $accounts = app(ManagedUserAccountResolver::class);
        $secondAttempt = TransferArchiveAttempt::create(
            $fixture->transfer, $accounts->resolve($fixture->instance->node), $accounts->resolve($fixture->destination),
        );
        $secondAttempt = $fixture->source->prepareArchives($secondAttempt);
        $second = $fixture->source->capture($fixture->instance, $secondAttempt, $fixture->sourceAttempt);

        expect($first->archiveIdentity)->not->toBe($second->archiveIdentity)
            ->and($fixture->source->cleanupArchives($fixture->attempt))->toBe([])
            ->and(is_file($second->archiveIdentity))->toBeTrue()
            ->and($fixture->source->cleanupArchives($secondAttempt))->toBe([]);
    } finally {
        $fixture->close();
    }
});

it('preserves a foreign workspace collision while settling the never-created destination', function (): void {
    $fixture = new TransferArchiveNativeFixture(prepare: false);
    $workspace = dirname($fixture->attempt->archivePath('source'));
    mkdir($workspace, 0700, recursive: true);
    file_put_contents($workspace.'/foreign', 'foreign-sentinel');

    try {
        expect(fn () => $fixture->prepare())->toThrow(ResourceOperationException::class);
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source'])
            ->and(file_get_contents($workspace.'/foreign'))->toBe('foreign-sentinel')
            ->and(is_dir(dirname($fixture->attempt->archivePath('destination'))))->toBeFalse();
    } finally {
        $fixture->close();
    }
});

it('settles a never-started attempt and refuses late preparation', function (): void {
    $fixture = new TransferArchiveNativeFixture(prepare: false);

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe([]);
        expect(fn () => $fixture->prepare())->toThrow(ResourceOperationException::class);
        expect(is_dir(dirname($fixture->attempt->archivePath('source'))))->toBeFalse()
            ->and($fixture->source->cleanupArchives($fixture->attempt))->toBe([]);
    } finally {
        $fixture->close();
    }
});

it('cleans empty prepared scopes after acknowledgement loss without starting payload capture', function (): void {
    $fixture = new TransferArchiveNativeFixture(prepare: false);
    $fixture->ssh->after = static function (SshConnection $connection, RemoteCommand $command, CommandResult $result): CommandResult {
        if ($command->arguments[3] === 'prepare' && $connection->host === '10.44.47.1') {
            throw new RuntimeException('archive-secret-sentinel');
        }

        return $result;
    };

    try {
        expect(fn () => $fixture->prepare())->toThrow(ResourceOperationException::class);
        expect(filesize($fixture->attempt->archivePath('source')))->toBe(0)
            ->and($fixture->attempt->source['receipt'])->toBeNull()
            ->and($fixture->source->cleanupArchives($fixture->attempt))->toBe([])
            ->and(is_dir(dirname($fixture->attempt->archivePath('source'))))->toBeFalse();
        expect(array_column(array_column($fixture->ssh->calls, 'command'), 'arguments'))
            ->each(fn ($arguments) => $arguments->not->toContain('capture'));
    } finally {
        $fixture->close();
    }
});

it('retains unconfirmed cleanup after a lost acknowledgement and confirms an identical retry', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->capture();
    $fixture->ssh->after = static function (SshConnection $connection, RemoteCommand $command, CommandResult $result): CommandResult {
        if ($command->arguments[3] === 'cleanup' && $connection->host === '10.44.47.1') {
            throw new RuntimeException('archive-secret-sentinel');
        }

        return $result;
    };

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source'])
            ->and(is_dir(dirname($fixture->attempt->archivePath('source'))))->toBeFalse();
        $fixture->ssh->after = null;
        expect($fixture->source->cleanupArchives($fixture->attempt->withCleanupPending(['source'])))->toBe([]);
        expect(fn () => $fixture->capture())
            ->toThrow(ResourceOperationException::class);
        expect(is_dir(dirname($fixture->attempt->archivePath('source'))))->toBeFalse();
    } finally {
        $fixture->close();
    }
});

it('unlinks a destination archive while an upload still holds its open descriptor', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $archive = $fixture->attempt->archivePath('destination');
    $upload = fopen($archive, 'wb');

    try {
        expect(is_resource($upload))->toBeTrue();
        fwrite($upload, 'first-archive-secret-sentinel');
        fflush($upload);

        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe([])
            ->and(fstat($upload)['nlink'])->toBe(0);
        fwrite($upload, 'late-archive-secret-sentinel');
        fflush($upload);

        expect(is_dir(dirname($archive)))->toBeFalse()
            ->and($fixture->source->cleanupArchives($fixture->attempt))->toBe([]);
        $root = $fixture->attempt->destination['private_root'];
        expect(array_values(array_diff(scandir($root), ['.', '..'])))->toBe([
            $fixture->attempt->id.'.json', $fixture->attempt->id.'.lock',
        ]);
    } finally {
        if (is_resource($upload)) {
            fclose($upload);
        }
        $fixture->close();
    }
});

it('refuses a late upload open after native archive cleanup removed its workspace', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $capture = $fixture->capture();
    $stage = $fixture->directory.'/late-upload.tar';
    copy($capture->archiveIdentity, $stage);

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe([]);
        $upload = new NativeProcessRunner()->run(new ProcessInvocation([
            'scp', '--', $stage, $fixture->attempt->archivePath('destination'),
        ]));

        expect($upload->succeeded())->toBeFalse()
            ->and(is_dir(dirname($fixture->attempt->archivePath('destination'))))->toBeFalse()
            ->and($fixture->source->cleanupArchives($fixture->attempt))->toBe([]);
        expect(fn () => $fixture->prepare())->toThrow(ResourceOperationException::class);
    } finally {
        $fixture->close();
    }
});

it('preserves unknown content inside an owned archive workspace until it can be resolved', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $capture = $fixture->capture();
    $foreign = dirname($capture->archiveIdentity).'/foreign';
    file_put_contents($foreign, 'foreign-sentinel');

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source'])
            ->and(file_get_contents($foreign))->toBe('foreign-sentinel')
            ->and(is_file($capture->archiveIdentity))->toBeTrue();
        unlink($foreign);
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe([]);
    } finally {
        $fixture->close();
    }
});

it('refuses a replaced workspace without deleting its foreign contents', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $capture = $fixture->capture();
    $workspace = dirname($capture->archiveIdentity);
    rename($workspace, $workspace.'.retained');
    mkdir($workspace, 0700);
    file_put_contents($workspace.'/foreign', 'foreign-sentinel');

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source'])
            ->and(file_get_contents($workspace.'/foreign'))->toBe('foreign-sentinel')
            ->and(is_file($workspace.'.retained/archive.tar'))->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('retains archive evidence when its private root identity changes', function (bool $symlink): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->capture();
    $root = $fixture->attempt->source['private_root'];
    rename($root, $root.'.retained');
    $foreign = $fixture->directory.'/foreign-root';
    mkdir($foreign, 0700);
    file_put_contents($foreign.'/sentinel', 'foreign-sentinel');
    if ($symlink) {
        symlink($foreign, $root);
    } else {
        mkdir($root, 0700);
        file_put_contents($root.'/sentinel', 'foreign-sentinel');
    }

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source'])
            ->and(file_get_contents($root.'/sentinel'))->toBe('foreign-sentinel')
            ->and(file_get_contents($foreign.'/sentinel'))->toBe('foreign-sentinel');
    } finally {
        $fixture->close();
    }
})->with(['symlink' => true, 'replacement' => false]);

it('preserves a foreign directory swapped between archive validation and its atomic claim', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->capture();
    $fixture->ssh->programReplacements = [
        'rename_without_replacement(root, workspace_name, claim_name)' => implode("\n", [
            'if side == "source":',
            '            os.rename(workspace_name, attempt + ".retained", src_dir_fd=root, dst_dir_fd=root)',
            '            os.mkdir(workspace_name, 0o700, dir_fd=root)',
            '            foreign = os.open(workspace_name + "/foreign", os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600, dir_fd=root)',
            '            os.write(foreign, b"foreign-sentinel")',
            '            os.close(foreign)',
            '        rename_without_replacement(root, workspace_name, claim_name)',
        ]),
    ];

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source']);
        $root = $fixture->attempt->source['private_root'];
        expect(file_get_contents(dirname($fixture->attempt->archivePath('source')).'/foreign'))->toBe('foreign-sentinel')
            ->and(is_file($root.'/'.$fixture->attempt->id.'.retained/archive.tar'))->toBeTrue()
            ->and(is_dir($root.'/'.$fixture->attempt->id.'.claimed'))->toBeFalse();
    } finally {
        $fixture->close();
    }
});

it('refuses an archive file substituted after workspace validation before opening the payload', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->ssh->programReplacements = [
        'archive = open_artifact(workspace, "archive", os.O_RDWR)' => implode("\n", [
            'os.rename("archive.tar", "retained.tar", src_dir_fd=workspace, dst_dir_fd=workspace)',
            '    foreign = os.open("archive.tar", os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600, dir_fd=workspace)',
            '    os.write(foreign, b"foreign-sentinel")',
            '    os.close(foreign)',
            '    archive = open_artifact(workspace, "archive", os.O_RDWR)',
        ]),
    ];

    try {
        expect(fn () => $fixture->capture())->toThrow(ResourceOperationException::class);
        expect(file_get_contents($fixture->attempt->archivePath('source')))->toBe('foreign-sentinel')
            ->and($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source']);
    } finally {
        $fixture->close();
    }
});

it('resumes exact archive cleanup after interruption at its claim or deletion boundary', function (string $fault): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->capture();
    $fixture->ssh->programReplacements = match ($fault) {
        'after claim' => [
            'moved = metadata_at(root, claim_name)' => 'raise OSError("after claim")',
        ],
        'during deletion' => [
            'os.unlink(name, dir_fd=workspace)' => "os.unlink(name, dir_fd=workspace)\n                raise OSError(\"during deletion\")",
        ],
    };

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source', 'destination']);
        $fixture->ssh->programReplacements = [];
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe([])
            ->and(is_dir(dirname($fixture->attempt->archivePath('source'))))->toBeFalse();
    } finally {
        $fixture->close();
    }
})->with(['after claim', 'during deletion']);

it('recovers archive cleanup after killed partial and complete metadata writes', function (bool $partial): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->capture();
    $journals = [];
    foreach (['source', 'destination'] as $side) {
        $journals[$side] = $fixture->attempt->{$side}['private_root'].'/'.$fixture->attempt->id.'.json';
    }
    $before = array_map(stat(...), $journals);
    $exitCodes = [];
    $fixture->ssh->after = function (SshConnection $connection, RemoteCommand $command, CommandResult $result) use (&$exitCodes): CommandResult {
        $exitCodes[] = $result->exitCode;

        return $result;
    };
    $fixture->ssh->programReplacements = [
        'written = os.pwrite(descriptor, frame[position:], offset + position)' => implode("\n", [
            'written = os.pwrite(descriptor, frame[position:position + 24] if '.($partial ? 'True' : 'False').' and value["phase"] == "cleaned" else frame[position:], offset + position)',
            '        if value["phase"] == "cleaned":',
            '            os.fsync(descriptor)',
            '            os.kill(os.getpid(), 9)',
        ]),
    ];

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source', 'destination'])
            ->and($exitCodes)->toBe([137, 137]);
        foreach (['source', 'destination'] as $side) {
            expect(is_dir(dirname($fixture->attempt->archivePath($side))))->toBeFalse();
        }
        $fixture->ssh->programReplacements = [];
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe([])
            ->and($fixture->source->cleanupArchives($fixture->attempt))->toBe([]);
        foreach ($journals as $side => $journal) {
            clearstatcache(true, $journal);
            $after = stat($journal);
            expect($after['ino'])->toBe($before[$side]['ino'])
                ->and($after['size'])->toBe(32768)
                ->and(file_exists($fixture->attempt->{$side}['private_root'].'/'.$fixture->attempt->id.'.next'))->toBeFalse();
        }
    } finally {
        $fixture->close();
    }
})->with(['partial write' => true, 'complete write' => false]);

it('retains archive payloads when their metadata journal has no valid ownership record', function (string $fault): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->capture();
    $archive = $fixture->attempt->archivePath('source');
    $hash = hash_file('sha256', $archive);
    $journal = $fixture->attempt->source['private_root'].'/'.$fixture->attempt->id.'.json';
    match ($fault) {
        'legacy format' => file_put_contents($journal, '{"phase":"ready"}'),
        'both torn' => file_put_contents($journal, str_repeat("\0", 32768)),
        'oversized' => file_put_contents($journal, str_repeat('x', 32769)),
        'wrong mode' => chmod($journal, 0644),
        'hard link' => link($journal, $journal.'.retained'),
    };

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source'])
            ->and(hash_file('sha256', $archive))->toBe($hash);
    } finally {
        $fixture->close();
    }
})->with(['legacy format', 'both torn', 'oversized', 'wrong mode', 'hard link']);

it('does not infer archive ownership from a killed initial metadata write', function (): void {
    $fixture = new TransferArchiveNativeFixture(prepare: false);
    $fixture->ssh->programReplacements = [
        'written = os.pwrite(descriptor, frame[position:], offset + position)' => implode("\n", [
            'written = os.pwrite(descriptor, frame[position:position + 24], offset + position)',
            '        os.fsync(descriptor)',
            '        os.kill(os.getpid(), 9)',
        ]),
    ];

    try {
        expect(fn () => $fixture->prepare())->toThrow(ResourceOperationException::class);
        $workspace = dirname($fixture->attempt->archivePath('source'));
        mkdir($workspace, 0700);
        file_put_contents($workspace.'/foreign', 'foreign-sentinel');
        $fixture->ssh->programReplacements = [];
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source'])
            ->and(file_get_contents($workspace.'/foreign'))->toBe('foreign-sentinel');
    } finally {
        $fixture->close();
    }
});

final class TransferArchiveNativeFixture
{
    public readonly string $directory;

    public readonly string $destinationPath;

    public readonly AppInstance $instance;

    public readonly Node $destination;

    public readonly TransferArchiveNativeSsh $ssh;

    public readonly TransferArchiveNativeTransport $transport;

    public readonly RemoteAppInstanceTransferSource $source;

    public readonly AppInstanceTransfer $transfer;

    public TransferArchiveAttempt $attempt;

    public TransferDestinationAttempt $destinationAttempt;

    public ?TransferSourceAttempt $sourceAttempt = null;

    public function __construct(bool $prepare = true)
    {
        $this->directory = sys_get_temp_dir().'/orbit-transfer-native-'.Str::uuid();
        mkdir($this->directory, 0700);
        $sourceHome = $this->directory.'/source-home';
        $destinationHome = $this->directory.'/destination-home';
        mkdir($sourceHome, 0700);
        mkdir($destinationHome, 0700);
        $checkout = $sourceHome.'/source-'.Str::uuid();
        mkdir($checkout, 0700);
        $this->destinationPath = $destinationHome.'/apps/shop/web';
        $account = posix_getpwuid(posix_geteuid());
        if (! is_array($account)) {
            throw new RuntimeException('The native archive fixture requires the current account.');
        }
        $user = $account['name'];
        $sourceNode = Node::query()->create([
            'name' => 'archive-source', 'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.201', 'wireguard_ip' => '10.44.47.1', 'user' => $user,
        ]);
        $this->destination = Node::query()->create([
            'name' => 'archive-destination', 'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.202', 'wireguard_ip' => '10.44.47.2', 'user' => $user,
        ]);
        $app = OrbitApp::query()->create([
            'name' => 'Archive fixture', 'slug' => 'archive-fixture',
            'repository_url' => 'https://example.test/archive-fixture.git',
        ]);
        $this->instance = AppInstance::query()->create([
            'app_id' => $app->id, 'node_id' => $sourceNode->id, 'name' => 'web',
            'environment' => 'development', 'checkout_path' => $checkout,
            'source_layout' => 'checkout', 'status' => AppInstanceState::Active,
        ]);
        $this->transfer = AppInstanceTransfer::query()->create([
            'app_instance_id' => $this->instance->id,
            'source_node_id' => $sourceNode->id,
            'destination_node_id' => $this->destination->id,
            'destination_name' => 'web', 'destination_path' => $this->destinationPath,
            'destination_domain' => 'web.archive.test', 'source_layout' => 'checkout',
            'source_path' => $checkout, 'source_route_id' => 1,
            'status' => 'reserved', 'current_step' => 'reserved',
        ]);
        file_put_contents($checkout.'/app.php', 'tracked source');
        file_put_contents($checkout.'/.env', "KEY=archive-secret-sentinel\n");
        $this->git(['init', '--quiet', '--initial-branch=main'], $checkout);
        $this->git(['add', 'app.php'], $checkout);
        $this->git(['-c', 'user.name=Archive Fixture', '-c', 'user.email=archive@example.test', 'commit', '--quiet', '-m', 'fixture'], $checkout);
        $keys = new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/transfer-test/key';
            }

            public function publicKey(): string
            {
                return 'transfer-test-public-key';
            }
        };
        $knownHosts = new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/transfer-test/known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        };
        app()->instance(ManagedUserAccountResolver::class, new class($sourceNode->id, $user, $sourceHome, $destinationHome) implements ManagedUserAccountResolver
        {
            public function __construct(
                private readonly int $sourceNodeId,
                private readonly string $user,
                private readonly string $sourceHome,
                private readonly string $destinationHome,
            ) {}

            public function resolve(Node $node): ManagedUserAccount
            {
                return new ManagedUserAccount(
                    $this->user,
                    $this->user,
                    $node->id === $this->sourceNodeId ? $this->sourceHome : $this->destinationHome,
                );
            }
        });
        $this->ssh = new TransferArchiveNativeSsh;
        $this->transport = new TransferArchiveNativeTransport;
        $this->source = app()->make(RemoteAppInstanceTransferSource::class, [
            'ssh' => new AppDevSshExecutor($this->ssh, $keys, $knownHosts),
            'processes' => $this->transport,
            'keys' => $keys,
            'knownHosts' => $knownHosts,
        ]);
        $accounts = app(ManagedUserAccountResolver::class);
        $this->attempt = TransferArchiveAttempt::create(
            $this->transfer, $accounts->resolve($sourceNode), $accounts->resolve($this->destination),
        );
        $this->destinationAttempt = TransferDestinationAttempt::create($this->transfer, $accounts->resolve($this->destination));
        $this->transfer->update(['destination_attempt' => $this->destinationAttempt->toArray()]);
        $this->transfer->update(['archive_attempt' => $this->attempt->toArray()]);
        if ($prepare) {
            $this->prepare();
        }
    }

    public function prepare(): void
    {
        $this->attempt = $this->source->prepareArchives($this->attempt);
        $this->transfer->update(['archive_attempt' => $this->attempt->toArray()]);
    }

    public function materialize(): TransferCheckout
    {
        try {
            $capture = $this->capture();
            $this->prepareDestination();

            return $this->source->materialize(
                $capture, $this->destination, StoragePath::parse($this->destinationPath), $this->attempt, $this->destinationAttempt,
            );
        } finally {
            expect($this->source->cleanupArchives($this->attempt))->toBe([]);
        }
    }

    public function capture(): TransferSourceCapture
    {
        $this->prepareSource();

        return $this->source->capture($this->instance, $this->attempt, $this->sourceAttempt);
    }

    public function prepareSource(): void
    {
        if ($this->sourceAttempt === null) {
            $this->transfer->update(['source_path' => $this->instance->checkout_path, 'source_layout' => $this->instance->source_layout]);
            $account = app(ManagedUserAccountResolver::class)->resolve($this->instance->node);
            $this->sourceAttempt = TransferSourceAttempt::create($this->transfer, $account)->acquiring();
            $this->transfer->update(['source_attempt' => $this->sourceAttempt->toArray()]);
        }
        if ($this->sourceAttempt->phase === 'acquiring') {
            $this->sourceAttempt = $this->source->prepareSource($this->sourceAttempt);
            $this->transfer->update([
                'source_attempt' => $this->sourceAttempt->toArray(),
                'common_repository_path' => $this->sourceAttempt->receipt['common_path'],
            ]);
        }
    }

    public function prepareDestination(): void
    {
        $this->destinationAttempt = $this->destinationAttempt->acquiring();
        $this->transfer->update(['destination_attempt' => $this->destinationAttempt->toArray()]);
        $this->destinationAttempt = $this->source->prepareDestination($this->destinationAttempt);
        $this->transfer->update(['destination_attempt' => $this->destinationAttempt->toArray()]);
    }

    public function destinationScope(): string
    {
        return dirname($this->destinationPath).'/.orbit-transfer-destinations';
    }

    public function destinationIntent(): TransferDestinationAttempt
    {
        return TransferDestinationAttempt::fromArray([
            ...$this->destinationAttempt->toArray(), 'phase' => 'acquiring', 'receipt' => null,
        ], $this->transfer);
    }

    /** @param non-empty-list<string> $arguments */
    public function git(array $arguments, string $path): string
    {
        $result = new NativeProcessRunner()->run(new ProcessInvocation(['git', '-C', $path, ...$arguments]));
        if (! $result->succeeded()) {
            throw new RuntimeException('The native archive Git fixture failed.');
        }

        return trim($result->stdout);
    }

    public function close(): void
    {
        File::deleteDirectory($this->directory);
    }
}

final class TransferArchiveNativeSsh implements SshExecutor
{
    /** @var list<array{connection: SshConnection, command: RemoteCommand}> */
    public array $calls = [];

    public ?Closure $before = null;

    public ?Closure $after = null;

    /** @var array<string, string> */
    public array $programReplacements = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->calls[] = ['connection' => $connection, 'command' => $command];
        if ($this->before instanceof Closure) {
            ($this->before)($connection, $command);
        }
        $arguments = $command->arguments;
        if ($arguments[0] === 'python3') {
            $arguments[2] = strtr($arguments[2], $this->programReplacements);
        }
        $previous = umask(0000);
        try {
            $result = new NativeProcessRunner()->run(new ProcessInvocation(
                $arguments, input: $command->input, protectedInput: $command->protectedInput,
            ));
        } finally {
            umask($previous);
        }
        if ($this->after instanceof Closure) {
            return ($this->after)($connection, $command, $result);
        }

        return $result;
    }
}

final class TransferArchiveNativeTransport implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $invocations = [];

    public string $uploadedPath = '';

    public ?string $failure = null;

    /** @var list<string> */
    public array $localStages = [];

    /** @var list<int> */
    public array $targetModes = [];

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;
        if ($invocation->arguments[0] !== 'scp') {
            throw new RuntimeException('The archive transport accepts only SCP.');
        }
        [$source, $target] = array_slice($invocation->arguments, -2);
        $remoteSource = $source;
        $source = $this->localPath($source);
        $localTarget = $this->localPath($target);
        $direction = $remoteSource !== $source ? 'download' : 'upload';
        if ($direction === 'download') {
            $this->localStages[] = $localTarget;
        }
        if ($localTarget !== $target) {
            $this->uploadedPath = $localTarget;
        }
        $this->targetModes[] = fileperms($localTarget) & 0777;
        if ($this->failure === $direction.' exception') {
            throw new RuntimeException('archive-secret-sentinel');
        }
        if ($this->failure === $direction.' typed exception') {
            throw new ResourceOperationException('transport.secret', 'archive-secret-sentinel', 500);
        }
        if ($direction === 'upload' && $this->failure === 'partial extraction') {
            file_put_contents($localTarget, substr(file_get_contents($source), 0, 1536).str_repeat('x', 512));

            return new CommandResult(0, '', '', 1, false);
        }
        if ($this->failure === $direction || $direction === 'upload' && in_array($this->failure, ['truncated upload', 'extraction'], true)) {
            file_put_contents($localTarget, 'partial-archive-secret-sentinel');

            return new CommandResult($this->failure === 'extraction' ? 0 : 1, 'archive-secret-sentinel', '', 1, $this->failure === 'truncated upload');
        }
        if (! copy($source, $localTarget)) {
            throw new RuntimeException('The native archive transport fixture could not copy the payload.');
        }

        return new CommandResult(0, '', '', 1, false);
    }

    private function localPath(string $path): string
    {
        if (preg_match('/\A[^@]+@(?:\[[^]]+\]|[^:]+):(.+)\z/D', $path, $match) !== 1) {
            return $path;
        }

        return $match[1];
    }
}
