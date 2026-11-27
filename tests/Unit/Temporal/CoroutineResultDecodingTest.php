<?php

declare(strict_types=1);

use App\Contracts\Outbox\DispatchOutboxWorkflowInterface;
use Nubos\Crm\Contracts\Calendar\SendBookingMailsWorkflowInterface;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\Type;
use Temporal\Exception\DataConverterException;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('hands a parent the result of a child workflow whose method is declared as a generator, with or without a value', function (): void {
    $converter = app(DataConverterInterface::class);

    $settledType = Type::create((new ReflectionMethod(DispatchOutboxWorkflowInterface::class, 'dispatch'))->getReturnType());
    $mailedType = Type::create((new ReflectionMethod(SendBookingMailsWorkflowInterface::class, 'run'))->getReturnType());

    expect($converter->fromPayload($converter->toPayload(3), $settledType))->toBe(3)
        ->and($converter->fromPayload($converter->toPayload(null), $mailedType))->toBeNull()
        ->and(fn (): mixed => $converter->fromPayload($converter->toPayload(null), Type::TYPE_STRING))
        ->toThrow(DataConverterException::class, 'Unable to convert null to non nullable type');
});
