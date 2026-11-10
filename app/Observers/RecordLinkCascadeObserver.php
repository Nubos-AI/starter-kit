<?php

declare(strict_types=1);

namespace App\Observers;

use App\Attributes\Engine\BackedByObjectType;
use App\Models\CustomRecord;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordLinkCascade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use ReflectionClass;

class RecordLinkCascadeObserver
{
    public function __construct(
        private readonly RecordLinkCascade $cascade,
        private readonly ObjectTypeRegistry $types,
    ) {}

    public function deleted(Model $model): void
    {
        if (method_exists($model, 'isForceDeleting') && !$model->isForceDeleting()) {
            return;
        }

        $objectTypeId = $this->objectTypeIdOf($model);

        if ($objectTypeId === null) {
            return;
        }

        $this->cascade->purge($objectTypeId, (string) $model->getKey());
    }

    private function objectTypeIdOf(Model $model): ?string
    {
        if ($model instanceof CustomRecord) {
            return $model->object_type_id;
        }

        $slug = $this->boundSlugOf($model);

        if ($slug === null) {
            return null;
        }

        try {
            return (string) $this->types->bySlug($slug)->getKey();
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    private function boundSlugOf(Model $model): ?string
    {
        $attributes = (new ReflectionClass($model))->getAttributes(BackedByObjectType::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->slug;
        }

        $configured = array_search($model::class, (array) config('engine.native_backings', []), true);

        return is_string($configured) ? $configured : null;
    }
}
