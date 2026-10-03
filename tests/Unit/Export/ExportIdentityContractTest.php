<?php

declare(strict_types=1);

use App\Enums\Export\ExportFormat;
use App\Enums\Export\ExportIdentityColumn;
use App\Enums\Import\ImportIdentityTarget;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('writes exactly the identity columns the import accepts as targets so a roundtrip closes', function (): void {
    $written = array_map(
        static fn (ExportIdentityColumn $column): string => $column->value,
        ExportIdentityColumn::cases(),
    );

    $accepted = array_map(
        static fn (ImportIdentityTarget $target): string => $target->value,
        ImportIdentityTarget::cases(),
    );

    expect($written)->toBe($accepted);
});

it('names the identity columns for the caller in german', function (): void {
    expect(ExportIdentityColumn::ExternalReferenceId->label())
        ->toBe(__('i18n.backend.enums.export.export_identity_column.external_reference_id'))
        ->and(ExportIdentityColumn::RecordNumber->label())
        ->toBe(__('i18n.backend.enums.export.export_identity_column.record_number'));
});

it('gives every offered export format its own file extension', function (): void {
    $extensions = array_map(
        static fn (ExportFormat $format): string => $format->extension(),
        ExportFormat::cases(),
    );

    expect($extensions)->toBe(array_unique($extensions))
        ->and($extensions)->not->toContain('');
});
