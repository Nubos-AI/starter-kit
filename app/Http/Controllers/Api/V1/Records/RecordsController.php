<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Records;

use App\Actions\Engine\CreateRecordAction;
use App\Actions\Engine\DeleteRecordAction;
use App\Actions\Engine\UpdateRecordAction;
use App\Exceptions\StaleRecordException;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Api\JsonApiRecordCollection;
use App\Http\Resources\Api\JsonApiRecordResource;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Api\ApiQueryCompiler;
use App\Support\Engine\ObjectTypeRegistry;
use App\Traits\Engine\RespondsWithRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Str;

#[Middleware('idempotency', only: ['store'])]
class RecordsController extends Controller
{
    use RespondsWithRecords;

    use AuthorizesRequests;

    public function __construct(
        private ObjectTypeRegistry $objectTypes,
        private ApiQueryCompiler $queryCompiler,
        private readonly CreateRecordAction $createRecordAction,
        private readonly UpdateRecordAction $updateRecordAction,
        private readonly DeleteRecordAction $deleteRecordAction,
    ) {}

    public function index(Request $request, string $typeSlug): JsonApiRecordCollection|JsonResponse
    {
        $type = $this->objectTypes->bySlug($typeSlug);

        $user = $request->user();
        $actor = $user instanceof User ? $user : null;
        $canViewType = $actor !== null && $actor->hasPermission("{$type->slug}.view");

        $rejection = $this->queryCompiler->reject($request, $type, $actor);

        if ($rejection !== null) {
            return $rejection;
        }

        $size = min(100, max(1, (int) $request->input('page.size', 25)));

        $cursor = $request->input('page.cursor');

        $query = CustomRecord::query()
            ->ofType($type)
            ->unless($canViewType, fn ($query) => $query->whereRaw('1 = 0'));

        $records = $this->queryCompiler->applyToQuery($query, $request, $type, $actor)
            ->cursorPaginate($size, ['*'], 'page[cursor]', is_string($cursor) ? $cursor : null);

        return new JsonApiRecordCollection($records);
    }

    public function show(Request $request, string $typeSlug, string $record): JsonApiRecordResource
    {
        $type = $this->objectTypes->bySlug($typeSlug);

        $record = $this->resolveRecordOfType($type, $record);

        $this->authorize('view', $record);

        return new JsonApiRecordResource($record);
    }

    public function store(Request $request, string $typeSlug): JsonResponse
    {
        $type = $this->objectTypes->bySlug($typeSlug);

        $user = $request->user();

        if (!$user instanceof User || !$user->hasPermission("{$type->slug}.create")) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.api.v1.records.records_controller.not_authorized_to_create_a_record_of_this_type'));
        }

        $validated = $request->validate(array_merge($this->sharedAttributeRules(), [
            'data.attributes.pipelineId' => ['nullable', 'string'],
            'data.attributes.stageId' => ['nullable', 'string'],
        ]));

        $record = $this->createRecordAction->execute(array_merge(
            $this->actionInput($validated),
            ['object_type_id' => $type->getKey()],
        ), isUserInput: true);

        return JsonApiRecordResource::make($record)->response()->setStatusCode(201);
    }

    public function update(Request $request, string $typeSlug, string $record): JsonApiRecordResource
    {
        $type = $this->objectTypes->bySlug($typeSlug);

        $record = $this->resolveRecordOfType($type, $record);

        $this->authorize('update', $record);

        $validated = $request->validate(array_merge($this->sharedAttributeRules(), [
            'data.attributes.version' => ['required', 'integer'],
        ]));

        try {
            $updated = $this->updateRecordAction->execute($record, array_merge(
                $this->actionInput($validated),
                ['version' => $request->input('data.attributes.version')],
            ));
        } catch (StaleRecordException $exception) {
            throw $exception->withRecord($this->freshRecord($record));
        }

        return new JsonApiRecordResource($updated);
    }

    public function destroy(string $typeSlug, string $record): Response
    {
        $type = $this->objectTypes->bySlug($typeSlug);

        $record = $this->resolveRecordOfType($type, $record);

        $this->authorize('delete', $record);

        $this->deleteRecordAction->execute($record);

        return response()->noContent();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function sharedAttributeRules(): array
    {
        return [
            'data' => ['required', 'array'],
            'data.attributes' => ['required', 'array'],
            'data.attributes.data' => ['nullable', 'array'],
            'data.attributes.ownerId' => ['nullable', 'string'],
            'data.attributes.externalReferenceId' => ['nullable', 'string'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function actionInput(array $validated): array
    {
        $envelope = $validated['data'] ?? null;
        $attributes = is_array($envelope) ? ($envelope['attributes'] ?? null) : null;
        $attributes = is_array($attributes) ? $attributes : [];

        $columns = [
            'data' => 'data',
            'ownerId' => 'owner_id',
            'externalReferenceId' => 'external_reference_id',
            ...config('modules.records.api_aliases', []),
        ];

        $input = [];

        foreach ($columns as $attribute => $column) {
            if (array_key_exists($attribute, $attributes)) {
                $input[$column] = $attributes[$attribute];
            }
        }

        return $input;
    }

    private function resolveRecordOfType(ObjectType $type, string $identifier): CustomRecord
    {
        $query = CustomRecord::query()->ofType($type);

        $record = Str::isUlid($identifier)
            ? $query->whereKey($identifier)->first()
            : $query->where('record_number', $identifier)->first();

        if (!$record instanceof CustomRecord) {
            throw (new ModelNotFoundException)->setModel(CustomRecord::class, [$identifier]);
        }

        return $record;
    }
}
