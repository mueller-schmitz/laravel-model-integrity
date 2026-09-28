<?php

declare(strict_types=1);

use MuellerSchmitz\ModelIntegrity\Verification\IntegrityError;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityErrorType;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult;

function integrityError(IntegrityErrorType $type, ?int $version = null, ?int $sequence = null): IntegrityError
{
    return new IntegrityError($type, 'message', 'App\Models\Invoice', '1', $version, $sequence);
}

it('passes without errors', function (): void {
    $result = new IntegrityResult([], checkedVersions: 3, lastValidVersion: 3);

    expect($result->passes())->toBeTrue()
        ->and($result->fails())->toBeFalse()
        ->and($result->errors())->toBeEmpty()
        ->and($result->checkedVersions())->toBe(3)
        ->and($result->lastValidVersion())->toBe(3);
});

it('fails with errors', function (): void {
    $result = new IntegrityResult([integrityError(IntegrityErrorType::HashMismatch, 2, 2)], 3, 1);

    expect($result->fails())->toBeTrue()
        ->and($result->passes())->toBeFalse()
        ->and($result->errors()->sole()->type)->toBe(IntegrityErrorType::HashMismatch);
});

it('orders errors by sequence', function (): void {
    $result = new IntegrityResult([
        integrityError(IntegrityErrorType::StateDrift),
        integrityError(IntegrityErrorType::BrokenChain, 3, 3),
        integrityError(IntegrityErrorType::HashMismatch, 2, 2),
    ], 3, 1);

    expect($result->errors()->pluck('sequence')->all())->toBe([2, 3, null]);
});

it('merges results and removes duplicate errors', function (): void {
    $first = new IntegrityResult([integrityError(IntegrityErrorType::HashMismatch, 2, 2)], 3, 1);
    $second = new IntegrityResult([
        integrityError(IntegrityErrorType::HashMismatch, 2, 2),
        integrityError(IntegrityErrorType::SequenceGap, null, 5),
    ], 2, null);

    $merged = IntegrityResult::combine([$first, $second], checkedVersions: 4);

    expect($merged->errors())->toHaveCount(2)
        ->and($merged->checkedVersions())->toBe(4)
        ->and($merged->lastValidVersion())->toBeNull();
});

it('keeps different violations of the same type at the same sequence', function (): void {
    $result = new IntegrityResult([
        new IntegrityError(IntegrityErrorType::BrokenChain, 'prev_hash does not match', 'App\Models\Invoice', '1', 3, 3),
        new IntegrityError(IntegrityErrorType::BrokenChain, 'global_prev_hash does not match', 'App\Models\Invoice', '1', 3, 3),
    ], 3, 2);

    expect($result->errors())->toHaveCount(2);
});

it('keeps the version-specific error when deduplicating', function (): void {
    $merged = IntegrityResult::combine([
        new IntegrityResult([integrityError(IntegrityErrorType::SequenceGap, null, 2)], 1, 1),
        new IntegrityResult([integrityError(IntegrityErrorType::SequenceGap, 3, 2)], 1, 1),
    ], 2);

    expect($merged->errors()->sole()->version)->toBe(3);
});

it('describes an error', function (): void {
    $error = new IntegrityError(IntegrityErrorType::BrokenChain, 'prev_hash does not match', 'App\Models\Invoice', '42', 3, 7);

    expect((string) $error)->toBe('[broken_chain] App\Models\Invoice#42 v3 (sequence 7): prev_hash does not match');
});
