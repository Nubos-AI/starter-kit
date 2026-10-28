<?php

declare(strict_types=1);

use App\Enums\Engine\RelationDirection;

it('maps the outgoing direction to from_record_id as its own column', function (): void {
    expect(RelationDirection::Outgoing->ownColumn())->toBe('from_record_id')
        ->and(RelationDirection::Outgoing->counterpartColumn())->toBe('to_record_id')
        ->and(RelationDirection::Outgoing->isOutgoing())->toBeTrue();
});

it('maps the incoming direction to to_record_id as its own column', function (): void {
    expect(RelationDirection::Incoming->ownColumn())->toBe('to_record_id')
        ->and(RelationDirection::Incoming->counterpartColumn())->toBe('from_record_id')
        ->and(RelationDirection::Incoming->isOutgoing())->toBeFalse();
});

it('keeps the wire values the relation endpoints already validate against', function (): void {
    expect(array_map(
        fn (RelationDirection $direction): string => $direction->value,
        RelationDirection::cases(),
    ))->toBe(['outgoing', 'incoming']);
});
