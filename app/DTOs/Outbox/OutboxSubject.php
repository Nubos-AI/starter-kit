<?php

declare(strict_types=1);

namespace App\DTOs\Outbox;

use Illuminate\Database\Eloquent\Model;

readonly class OutboxSubject
{
    public function __construct(public string $type, public string $id) {}

    public static function of(Model $model): self
    {
        return new self($model->getMorphClass(), (string) $model->getKey());
    }
}
