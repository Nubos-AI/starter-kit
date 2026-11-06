<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Contracts\ConfigBundle\ArtifactWriterInterface;
use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Promotion\DiffState;
use App\Models\User;
use Closure;

class RecordingArtifactWriter implements ArtifactWriterInterface
{
    /**
     * @var list<array{key: string, state: DiffState}>
     */
    public array $applied = [];

    /**
     * @var list<string>
     */
    public array $removed = [];

    public int $flushes = 0;

    /**
     * @param  list<ArtifactKind>  $kinds
     * @param  Closure(BundleArtifact, DiffState, int): ArtifactWriteResult|null  $answer
     * @param  list<ArtifactWriteResult>  $flushResults
     */
    public function __construct(
        private readonly array $kinds,
        private readonly ?Closure $answer = null,
        private readonly array $flushResults = [],
    ) {}

    public function supports(ArtifactKind $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }

    public function apply(User $actingUser, ArtifactKind $kind, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $this->applied[] = ['key' => $artifact->key, 'state' => $state];

        $attempt = count(array_filter(
            $this->applied,
            static fn (array $seen): bool => $seen['key'] === $artifact->key,
        ));

        if ($this->answer instanceof Closure) {
            return ($this->answer)($artifact, $state, $attempt);
        }

        return new ArtifactWriteResult(
            kind: $kind,
            key: $artifact->key,
            action: ArtifactWriteAction::Created,
            modelId: null,
        );
    }

    public function remove(User $actingUser, ArtifactKind $kind, string $key): ArtifactWriteResult
    {
        $this->removed[] = $key;

        return new ArtifactWriteResult(
            kind: $kind,
            key: $key,
            action: ArtifactWriteAction::Removed,
            modelId: null,
        );
    }

    /**
     * @return list<ArtifactWriteResult>
     */
    public function flush(User $actingUser): array
    {
        $this->flushes++;

        return $this->flushResults;
    }

    /**
     * @return list<string>
     */
    public function appliedKeys(): array
    {
        return array_map(static fn (array $seen): string => $seen['key'], $this->applied);
    }
}
