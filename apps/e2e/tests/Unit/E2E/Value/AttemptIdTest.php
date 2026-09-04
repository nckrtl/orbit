<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\ProofStatus;

describe('AttemptId', function (): void {
    it('generates exactly 32 lowercase hexadecimal characters', function (): void {
        expect((string) AttemptId::generate())->toMatch('/\A[0-9a-f]{32}\z/D');
    });

    it('generates a distinct identity per attempt', function (): void {
        expect(AttemptId::generate()->value)->not->toBe(AttemptId::generate()->value);
    });

    it('rejects anything but exact 32 lowercase hexadecimal characters', function (string $value): void {
        expect(fn () => new AttemptId($value))->toThrow(InvalidArgumentException::class);
    })->with([
        'issue identity' => ['TST-123'],
        'empty' => [''],
        'uppercase hexadecimal' => [str_repeat('A', 32)],
        'too short' => [str_repeat('a', 31)],
        'too long' => [str_repeat('a', 33)],
        'trailing newline' => [str_repeat('a', 32)."\n"],
        'non hexadecimal' => [str_repeat('g', 32)],
    ]);

    it('keeps a short readable prefix of the exact identity', function (): void {
        expect(new AttemptId(str_repeat('a', 24).str_repeat('b', 8))->short())->toBe(str_repeat('a', 8));
    });
});

describe('attempt lifecycle enums', function (): void {
    it('names the two attempt purposes', function (): void {
        expect(AttemptPurpose::Discovery->value)
            ->toBe('discovery')
            ->and(AttemptPurpose::Proof->value)
            ->toBe('proof')
            ->and(AttemptPurpose::cases())
            ->toBe([
                AttemptPurpose::Discovery,
                AttemptPurpose::Proof,
                AttemptPurpose::CandidateConvergence,
            ]);
    });

    it('names the two proof statuses', function (): void {
        expect(ProofStatus::Proved->value)
            ->toBe('proved')
            ->and(ProofStatus::Diagnosis->value)
            ->toBe('diagnosis')
            ->and(ProofStatus::cases())
            ->toBe([ProofStatus::Proved, ProofStatus::Diagnosis]);
    });
});
