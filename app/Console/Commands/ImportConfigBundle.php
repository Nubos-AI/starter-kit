<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ConfigBundle\ImportConfigBundleAction;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Exceptions\ConfigBundle\UndecidedConflictsException;
use App\Models\PromotionRun;
use App\Support\Console\ActingUserResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportConfigBundle extends Command
{
    protected $signature = 'config:import {--as-user= : E-mail address or ULID of the acting user (required)} {--path= : Source directory (required)} {--label= : Label of the source installation (required)}';

    protected $description = 'Import a configuration bundle from a directory into the tenant of the acting user';

    /**
     * @var array<string, string>
     */
    private array $actionWords = [
        ArtifactWriteAction::Created->value => 'angelegt',
        ArtifactWriteAction::Updated->value => 'geändert',
        ArtifactWriteAction::Removed->value => 'entfernt',
        ArtifactWriteAction::Skipped->value => 'übersprungen',
    ];

    public function handle(ActingUserResolver $resolver, ImportConfigBundleAction $action): int
    {
        $actingUserId = null;
        $label = $this->textOption('label') ?? '';

        try {
            $actor = $resolver->resolve($this->textOption('as-user'));
            $actingUserId = (string) $actor->getKey();
            $run = $action->execute($actor, $this->textOption('path') ?? '', $label);
        } catch (UndecidedConflictsException $exception) {
            Log::warning('Config import command refused a bundle with undecided conflicts.', [
                'acting_user_id' => $actingUserId,
                'conflict_count' => count($exception->affected),
            ]);

            $this->error($exception->getMessage());

            foreach ($exception->affected as $conflict) {
                $this->line(ArtifactKind::from($conflict['kind'])->identifierFor($conflict['key']));
            }

            $this->error('Bitte entscheiden Sie diese Konflikte in der Prüfansicht der Oberfläche und starten Sie den Import danach erneut.');

            return self::FAILURE;
        } catch (Throwable $exception) {
            Log::error('Config import command failed.', [
                'acting_user_id' => $actingUserId,
                'exception_class' => $exception::class,
            ]);

            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $counts = array_fill_keys(array_keys($this->actionWords), 0);

        foreach ($this->resultsOf($run) as $result) {
            $counts[$result['action']] = ($counts[$result['action']] ?? 0) + 1;
            $word = $this->actionWords[$result['action']] ?? $result['action'];

            $this->line("{$word}  {$result['identifier']}");
        }

        $created = $counts[ArtifactWriteAction::Created->value];
        $updated = $counts[ArtifactWriteAction::Updated->value];
        $removed = $counts[ArtifactWriteAction::Removed->value];
        $skipped = $counts[ArtifactWriteAction::Skipped->value];

        $this->info("Import {$run->getKey()} ({$label}, {$run->counterpart_key}) abgeschlossen: {$created} angelegt, {$updated} geändert, {$removed} entfernt, {$skipped} übersprungen.");

        return self::SUCCESS;
    }

    private function textOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<array{action: string, identifier: string}>
     */
    private function resultsOf(PromotionRun $run): array
    {
        $results = $run->report['results'] ?? null;
        $lines = [];

        foreach (is_array($results) ? $results : [] as $result) {
            $kind = data_get($result, 'kind');
            $key = data_get($result, 'key');
            $writeAction = data_get($result, 'action');

            if (!is_string($kind) || !is_string($key) || !is_string($writeAction)) {
                continue;
            }

            $lines[] = [
                'action' => $writeAction,
                'identifier' => ArtifactKind::tryFrom($kind)?->identifierFor($key) ?? "{$kind}:{$key}",
            ];
        }

        return $lines;
    }
}
