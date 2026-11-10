<?php

declare(strict_types=1);

namespace App\Support\Promotion;

use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\DTOs\Promotion\ArtifactDiff;
use App\DTOs\Promotion\BundleDiff;
use App\DTOs\Promotion\DependencyResolution;
use App\DTOs\Promotion\PromotionSelection;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\DiffState;
use App\Exceptions\Promotion\CyclicArtifactDependencyException;
use Illuminate\Support\Str;

class ArtifactDependencyResolver
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $declarations = null;

    /**
     * @var list<string>|null
     */
    private ?array $reservedTokens = null;

    private ?string $placeholder = null;

    /**
     * @param  list<array{kind: ArtifactKind, key: string, reason: string}>  $targetRefusals
     */
    public function resolve(PromotionSelection $selection, ConfigBundle $source, BundleDiff $diff, array $targetRefusals = []): DependencyResolution
    {
        $knownKeys = $this->knownKeysOf($source, $diff);
        $limit = max(1, 2 * (count($source->artifacts) + $selection->count()) + 1);

        /** @var array<string, array{kind: ArtifactKind, key: string, optional: bool}> $pulled */
        $pulled = [];

        /** @var array<string, array{kind: ArtifactKind, key: string, reason: string}> $refused */
        $refused = [];

        /** @var array<string, array{kind: ArtifactKind, key: string, reason: string}> $blocked */
        $blocked = [];

        foreach ($targetRefusals as $refusal) {
            $identifier = $refusal['kind']->identifierFor($refusal['key']);
            $blocked[$identifier] = ['kind' => $refusal['kind'], 'key' => $refusal['key'], 'reason' => $refusal['reason']];

            if ($selection->contains($refusal['kind'], $refusal['key'])) {
                $refused[$identifier] = $blocked[$identifier];
            }
        }

        for ($pass = 0; ; $pass++) {
            if ($pass >= $limit) {
                throw CyclicArtifactDependencyException::cycle($this->identifiersOf($selection));
            }

            $selection = $this->withoutRefused($selection, $refused);

            foreach (array_keys($refused) as $identifier) {
                unset($pulled[$identifier]);
            }

            $changed = false;

            foreach ($selection->pairs as $pair) {
                $artifact = $source->find($pair['kind'], $pair['key']);

                if ($artifact === null) {
                    continue;
                }

                $identifier = $artifact->kind->identifierFor($artifact->key);
                $reason = null;

                /** @var list<array{kind: ArtifactKind, key: string, optional: bool}> $pulls */
                $pulls = [];

                foreach ($this->demandsFor($artifact, $knownKeys) as $demand) {
                    $verdict = $this->classify($demand, $selection, $source, $diff, $refused + $blocked);

                    if ($verdict === 'satisfied') {
                        continue;
                    }

                    if ($verdict === 'pull' || $verdict === 'hint') {
                        $kind = $demand['kind'];
                        $key = $demand['key'];

                        if ($kind === null || $key === null) {
                            continue;
                        }

                        $pulls[] = ['kind' => $kind, 'key' => $key, 'optional' => $verdict === 'hint'];

                        continue;
                    }

                    $reason = $this->reasonFor($verdict, $artifact->kind, $artifact->key, $demand);

                    break;
                }

                if ($reason !== null) {
                    if (!isset($refused[$identifier])) {
                        $refused[$identifier] = ['kind' => $artifact->kind, 'key' => $artifact->key, 'reason' => $reason];
                        $changed = true;
                    }

                    continue;
                }

                foreach ($pulls as $pull) {
                    $target = $pull['kind']->identifierFor($pull['key']);

                    if (isset($refused[$target])) {
                        continue;
                    }

                    $known = $pulled[$target] ?? null;

                    if ($known !== null && (!$known['optional'] || $pull['optional'])) {
                        continue;
                    }

                    $pulled[$target] = $pull;
                    $changed = true;

                    if (!$pull['optional']) {
                        $selection = $selection->withAdded($pull['kind'], $pull['key']);
                    }
                }
            }

            if (!$changed) {
                break;
            }
        }

        return new DependencyResolution(
            selection: $selection,
            pulledIn: array_values($pulled),
            refusals: array_values($refused),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function knownKeysOf(ConfigBundle $source, BundleDiff $diff): array
    {
        /** @var array<string, array<string, true>> $seen */
        $seen = [];

        foreach ($source->artifacts as $artifact) {
            $seen[$artifact->kind->value][$artifact->key] = true;
        }

        foreach ($diff->diffs as $entry) {
            $seen[$entry->kind->value][$entry->key] = true;
        }

        return array_map(static fn (array $keys): array => array_keys($keys), $seen);
    }

    /**
     * @param  array<string, list<string>>  $knownKeys
     * @return list<array{kind: ArtifactKind|null, key: string|null, optional: bool, problem: string|null}>
     */
    private function demandsFor(BundleArtifact $artifact, array $knownKeys): array
    {
        $entry = $this->declarationFor($artifact->kind);
        $demands = [];

        foreach (['requires', 'optional'] as $category) {
            foreach ($this->listOf($entry, $category) as $edge) {
                $kind = ArtifactKind::tryFrom($this->textOf($edge, 'kind'));

                if ($kind === null) {
                    continue;
                }

                $candidate = $this->candidateKeyFor($edge, $artifact, $knownKeys);

                if ($candidate === null) {
                    continue;
                }

                $optional = $category === 'optional' && $this->textOf($edge, 'source') === 'payload';

                if ($this->isPlaceholder($candidate)) {
                    if (!$optional) {
                        $demands[] = $this->demand($kind, null, false, 'placeholder');
                    }

                    continue;
                }

                $demands[] = $this->demand($kind, $candidate, $optional, null);
            }
        }

        return [...$demands, ...$this->settledSourcesFor($artifact, $entry)];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<array{kind: ArtifactKind|null, key: string|null, optional: bool, problem: string|null}>
     */
    private function settledSourcesFor(BundleArtifact $artifact, array $entry): array
    {
        $demands = [];

        foreach ($this->listOf($entry, 'exclusive') as $group) {
            $settled = [];

            foreach ($this->listOf($group, 'members') as $member) {
                $column = $this->textOf($member, 'column');

                if ($column !== '' && ($artifact->payload[$column] ?? null) !== null) {
                    $settled[] = $member;
                }
            }

            if ($settled === []) {
                $demands[] = $this->demand(null, null, false, 'sourceless');

                continue;
            }

            foreach ($settled as $member) {
                $kind = ArtifactKind::tryFrom($this->textOf($member, 'kind'));
                $value = $artifact->payload[$this->textOf($member, 'column')] ?? null;

                if ($kind === null || !is_string($value) || $value === '') {
                    continue;
                }

                if ($this->isPlaceholder($value)) {
                    $demands[] = $this->demand($kind, null, false, 'placeholder');

                    continue;
                }

                $demands[] = $this->demand($kind, $value, false, null);
            }
        }

        return $demands;
    }

    /**
     * @return array{kind: ArtifactKind|null, key: string|null, optional: bool, problem: string|null}
     */
    private function demand(?ArtifactKind $kind, ?string $key, bool $optional, ?string $problem): array
    {
        return ['kind' => $kind, 'key' => $key, 'optional' => $optional, 'problem' => $problem];
    }

    /**
     * @param  array<string, mixed>  $edge
     * @param  array<string, list<string>>  $knownKeys
     */
    private function candidateKeyFor(array $edge, BundleArtifact $artifact, array $knownKeys): ?string
    {
        if ($this->textOf($edge, 'source') === 'payload') {
            $value = $artifact->payload[$this->textOf($edge, 'field')] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        }

        $components = explode(':', $artifact->key);
        $derivation = $this->textOf($edge, 'derivation');

        if ($derivation === 'recompose') {
            $qualifier = $edge['qualifier'] ?? null;
            $qualifier = is_array($qualifier) ? $qualifier : [];
            $component = array_slice($components, $this->intOf($edge, 'component'), 1);
            $prefix = implode(':', array_slice($components, $this->intOf($qualifier, 'offset'), $this->lengthOf($qualifier)));

            if ($component === [] || $prefix === '' || $this->isReservedComponent($component[0])) {
                return null;
            }

            return $prefix.':'.$component[0];
        }

        if ($derivation === 'prefix') {
            $known = $knownKeys[$this->textOf($edge, 'kind')] ?? [];

            for ($length = count($components) - 1; $length > 0; $length--) {
                $candidate = implode(':', array_slice($components, 0, $length));

                if ($this->isReservedComponent($candidate)) {
                    return null;
                }

                if (in_array($candidate, $known, true)) {
                    return $candidate;
                }
            }
        }

        $fallback = implode(':', array_slice($components, $this->intOf($edge, 'offset'), $this->lengthOf($edge)));

        if ($fallback === '' || $this->isReservedComponent($fallback)) {
            return null;
        }

        return $fallback;
    }

    /**
     * @param  array{kind: ArtifactKind|null, key: string|null, optional: bool, problem: string|null}  $demand
     * @param  array<string, array{kind: ArtifactKind, key: string, reason: string}>  $refused
     */
    private function classify(array $demand, PromotionSelection $selection, ConfigBundle $source, BundleDiff $diff, array $refused): string
    {
        if ($demand['problem'] !== null) {
            return 'refuse-'.$demand['problem'];
        }

        $kind = $demand['kind'];
        $key = $demand['key'];

        if ($kind === null || $key === null) {
            return 'satisfied';
        }

        $entry = $this->diffFor($diff, $kind, $key);
        $settled = $entry !== null && $entry->state === DiffState::Unchanged && $entry->targetHash !== null;

        if ($demand['optional']) {
            return $selection->contains($kind, $key) || $settled ? 'satisfied' : 'hint';
        }

        if (isset($refused[$kind->identifierFor($key)])) {
            return 'refuse-cascade';
        }

        if ($selection->contains($kind, $key)) {
            return 'satisfied';
        }

        if ($entry !== null && $entry->state === DiffState::Conflicted) {
            return 'refuse-conflicted';
        }

        if ($settled) {
            return 'satisfied';
        }

        return $source->find($kind, $key) !== null ? 'pull' : 'refuse-missing';
    }

    private function diffFor(BundleDiff $diff, ArtifactKind $kind, string $key): ?ArtifactDiff
    {
        foreach ($diff->diffs as $entry) {
            if ($entry->kind === $kind && $entry->key === $key) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array{kind: ArtifactKind|null, key: string|null, optional: bool, problem: string|null}  $demand
     */
    private function reasonFor(string $verdict, ArtifactKind $kind, string $key, array $demand): string
    {
        $subject = Str::ucfirst($this->labelFor($kind)).' '.$this->quoted($key);
        $target = $demand['kind'] === null ? '' : $this->labelFor($demand['kind']);
        $name = $demand['key'] === null ? '' : $this->quoted($demand['key']);

        $cause = match ($verdict) {
            'refuse-conflicted' => __('i18n.backend.support.promotion.artifact_dependency_resolver.must_be_resolved_first', ['value1' => $target, 'value2' => $name]),
            'refuse-cascade' => __('i18n.backend.support.promotion.artifact_dependency_resolver.cannot_itself_be_transferred', ['value1' => $target, 'value2' => $name]),
            'refuse-placeholder' => __('i18n.backend.support.promotion.artifact_dependency_resolver.could_not_be_resolved_during_export', ['value1' => $target]),
            'refuse-sourceless' => __('i18n.backend.support.promotion.artifact_dependency_resolver.no_source_is_specified'),
            default => __('i18n.backend.support.promotion.artifact_dependency_resolver.is_missing_from_the_package', ['value1' => $target, 'value2' => $name]),
        };

        return __('i18n.backend.support.promotion.artifact_dependency_resolver.cannot_be_transferred_because', ['value1' => $subject, 'value2' => $cause]);
    }

    private function quoted(string $key): string
    {
        return '„'.$key.'“';
    }

    private function labelFor(ArtifactKind $kind): string
    {
        return match ($kind) {
            ArtifactKind::ObjectTypes => 'der Objekttyp',
            ArtifactKind::FieldGroups => 'die Feldgruppe',
            ArtifactKind::FieldDefinitions => 'das Feld',
            ArtifactKind::RelationshipTypes => 'die Beziehungsart',
            ArtifactKind::Pipelines => 'die Pipeline',
            ArtifactKind::PipelineStages => 'die Stage',
            ArtifactKind::StageTransitions => __('i18n.backend.support.promotion.artifact_dependency_resolver.the_stage_transition'),
            ArtifactKind::TransitionGates => __('i18n.backend.support.promotion.artifact_dependency_resolver.the_transition_condition'),
            ArtifactKind::FieldDependencies => __('i18n.backend.support.promotion.artifact_dependency_resolver.the_field_dependency'),
            ArtifactKind::MergeRules => __('i18n.backend.support.promotion.artifact_dependency_resolver.the_merge_rule'),
            ArtifactKind::ReminderTypes => 'die Erinnerungsart',
            ArtifactKind::Roles => 'die Rolle',
            ArtifactKind::RolePermissions => 'das Rollenrecht',
            ArtifactKind::FieldPermissions => 'das Feldrecht',
            ArtifactKind::TeamRecordAccessRules => 'die Team-Zugriffsregel',
            ArtifactKind::Automations => 'der Ablauf',
            ArtifactKind::AutomationTemplates => 'die Ablaufvorlage',
            ArtifactKind::NotificationRules => 'die Benachrichtigungsregel',
            ArtifactKind::NotificationTypeDefaults => 'die Benachrichtigungsvorgabe',
            ArtifactKind::WebhookSubscriptions => 'die Webhook-Anbindung',
            ArtifactKind::Reports => 'die Auswertung',
            ArtifactKind::Dashboards => 'das Dashboard',
            ArtifactKind::DashboardWidgets => 'die Kachel',
            ArtifactKind::Goals => 'das Ziel',
            ArtifactKind::Segments => 'das Segment',
            ArtifactKind::DocumentTemplates => 'die Dokumentvorlage',
            ArtifactKind::ExportFieldPresets => 'die Exportvorlage',
            ArtifactKind::ImportMappingPresets => 'die Importvorlage',
            ArtifactKind::AgingRules => 'die Alterungsregel',
            ArtifactKind::Skills => __('i18n.backend.support.promotion.artifact_dependency_resolver.the_skill'),
        };
    }

    /**
     * @param  array<string, array{kind: ArtifactKind, key: string, reason: string}>  $refused
     */
    private function withoutRefused(PromotionSelection $selection, array $refused): PromotionSelection
    {
        if ($refused === []) {
            return $selection;
        }

        return new PromotionSelection(array_values(array_filter(
            $selection->pairs,
            static fn (array $pair): bool => !isset($refused[$pair['kind']->identifierFor($pair['key'])]),
        )));
    }

    /**
     * @return list<string>
     */
    private function identifiersOf(PromotionSelection $selection): array
    {
        return array_map(
            static fn (array $pair): string => $pair['kind']->identifierFor($pair['key']),
            $selection->pairs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function declarationFor(ArtifactKind $kind): array
    {
        if ($this->declarations === null) {
            $configured = config('engine.artifact_dependencies');
            $declarations = [];

            foreach (is_array($configured) ? $configured : [] as $name => $entry) {
                if (is_string($name) && is_array($entry)) {
                    $declarations[$name] = $entry;
                }
            }

            $this->declarations = $declarations;
        }

        return $this->declarations[$kind->value] ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function listOf(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        $entries = [];

        foreach (is_array($value) ? $value : [] as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function textOf(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function intOf(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function lengthOf(array $data): ?int
    {
        $value = $data['length'] ?? null;

        return is_int($value) ? $value : null;
    }

    private function isReservedComponent(string $value): bool
    {
        if ($this->reservedTokens === null) {
            $configured = config('engine.config_bundle.reserved_tokens');
            $tokens = [];

            foreach (is_array($configured) ? $configured : [] as $token) {
                if (is_string($token)) {
                    $tokens[] = $token;
                }
            }

            $this->reservedTokens = $tokens;
        }

        return in_array($value, $this->reservedTokens, true);
    }

    private function isPlaceholder(string $value): bool
    {
        if ($this->placeholder === null) {
            $configured = config('engine.config_bundle.placeholder');
            $this->placeholder = is_string($configured) ? $configured : '';
        }

        return $this->placeholder !== '' && $value === $this->placeholder;
    }
}
