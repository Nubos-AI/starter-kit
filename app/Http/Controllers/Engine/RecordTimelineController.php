<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Enums\Audit\ActorType;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Timeline\TimelineEntryResource;
use App\Support\Engine\RecordRouteResolver;
use App\Support\Timeline\TimelineQuery;
use App\Support\Timeline\TimelineSourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\ScrollMetadata;

class RecordTimelineController extends Controller
{
    private string $pageComponent = 'records/Form';

    private string $cursorName = 'timelineCursor';

    private string $bareDatePattern = '/^\d{4}-\d{2}-\d{2}$/';

    public function __construct(
        private readonly RecordRouteResolver $recordRouteResolver,
        private readonly TimelineSourceRegistry $registry,
        private readonly TimelineQuery $timelineQuery,
    ) {}

    public function __invoke(Request $request, string $record): Response|JsonResponse|RedirectResponse
    {
        $user = $this->actingUser($request);
        $resolved = $this->recordRouteResolver->resolve($record, withTrashed: true);

        if ($user->cannot('view', $resolved)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.engine.record_timeline_controller.you_may_not_view_this_record'));
        }

        $validator = Validator::make($request->query(), [
            'sources' => ['nullable', 'array', 'max:'.count($this->registry->keys())],
            'sources.*' => ['string', 'distinct', Rule::in($this->registry->keys())],
            'occurredFrom' => ['nullable', 'date'],
            'occurredTo' => ['nullable', 'date', 'after_or_equal:occurredFrom'],
            'actorType' => ['nullable', Rule::enum(ActorType::class), 'required_with:actorId'],
            'actorId' => ['nullable', 'ulid'],
            $this->cursorName => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('i18n.backend.http.controllers.engine.record_timeline_controller.the_provided_data_is_invalid'),
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->header('X-Inertia-Partial-Component') !== $this->pageComponent) {
            return redirect()->route('engine.records.show', ['record' => $resolved->getKey()]);
        }

        $validated = $validator->validated();
        $actorType = $validated['actorType'] ?? null;

        $sourceKeys = $this->sourceKeys($validated['sources'] ?? null);
        $occurredFrom = $this->rangeBound($validated['occurredFrom'] ?? null, endOfDay: false);
        $occurredTo = $this->rangeBound($validated['occurredTo'] ?? null, endOfDay: true);
        $actor = is_string($actorType) ? ActorType::from($actorType) : null;
        $actorId = is_string($validated['actorId'] ?? null) ? $validated['actorId'] : null;

        $fingerprint = $this->filterFingerprint($sourceKeys, $occurredFrom, $occurredTo, $actor, $actorId);

        $encodedCursor = $this->encodedCursorOfFilterSet(
            is_string($validated[$this->cursorName] ?? null) ? $validated[$this->cursorName] : null,
            $fingerprint,
        );

        $paginator = $this->timelineQuery->paginate(
            record: $resolved,
            sourceKeys: $sourceKeys,
            occurredFrom: $occurredFrom,
            occurredTo: $occurredTo,
            actorType: $actor,
            actorId: $actorId,
            cursor: $encodedCursor,
            cursorName: $this->cursorName,
        );

        return Inertia::render($this->pageComponent, [
            'timelineEntries' => Inertia::scroll(
                fn () => TimelineEntryResource::redactedCollection(
                    $user,
                    $resolved,
                    $paginator->getCollection(),
                    $this->registry,
                ),
                'data',
                fn (): ScrollMetadata => new ScrollMetadata(
                    $this->cursorName,
                    $this->stampedCursor($paginator->previousCursor()?->encode(), $fingerprint),
                    $this->stampedCursor($paginator->nextCursor()?->encode(), $fingerprint),
                    $this->stampedCursor($encodedCursor, $fingerprint) ?? 1,
                ),
            ),
        ]);
    }

    /**
     * @param  list<string>  $sourceKeys
     */
    private function filterFingerprint(
        array $sourceKeys,
        ?CarbonImmutable $occurredFrom,
        ?CarbonImmutable $occurredTo,
        ?ActorType $actorType,
        ?string $actorId,
    ): string {
        sort($sourceKeys);

        return substr(hash('xxh128', (string) json_encode([
            $sourceKeys,
            $occurredFrom?->toIso8601String(),
            $occurredTo?->toIso8601String(),
            $actorType?->value,
            $actorId,
        ])), 0, 12);
    }

    private function stampedCursor(?string $encodedCursor, string $fingerprint): ?string
    {
        return $encodedCursor === null ? null : $fingerprint.'.'.$encodedCursor;
    }

    private function encodedCursorOfFilterSet(?string $cursor, string $fingerprint): ?string
    {
        if ($cursor === null || !str_starts_with($cursor, $fingerprint.'.')) {
            return null;
        }

        return substr($cursor, strlen($fingerprint) + 1);
    }

    /**
     * @return list<string>
     */
    private function sourceKeys(mixed $sources): array
    {
        return is_array($sources) ? array_values(array_filter($sources, 'is_string')) : [];
    }

    private function rangeBound(mixed $value, bool $endOfDay): ?CarbonImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parsed = CarbonImmutable::parse($value);

        return $endOfDay && preg_match($this->bareDatePattern, $value) === 1
            ? $parsed->endOfDay()
            : $parsed;
    }
}
