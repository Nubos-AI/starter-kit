<?php

declare(strict_types=1);

use App\Enums\ConfigBundle\ArtifactKind;
use Tests\Support\InstalledArtifactKinds;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $declared = config('engine.artifact_dependencies');

    $this->declarations = is_array($declared) ? $declared : [];

    $configured = config('engine.tenant_artifacts');

    $this->entries = array_values(array_filter(is_array($configured) ? $configured : [], 'is_array'));

    $bundleTables = [];
    $tableForKind = [];
    $remapFor = [];

    foreach ($this->entries as $entry) {
        $table = $entry['table'] ?? null;

        if (!is_string($table)) {
            continue;
        }

        $remap = $entry['remap'] ?? null;
        $remapFor[$table] = is_array($remap) ? $remap : [];

        if (($entry['bundle'] ?? false) !== true) {
            continue;
        }

        $bundleTables[] = $table;

        $kind = $entry['kind'] ?? null;

        if (is_string($kind)) {
            $tableForKind[$kind] = $table;
        }
    }

    $this->bundleTables = $bundleTables;
    $this->tableForKind = $tableForKind;
    $this->remapFor = $remapFor;

    $this->edgeVocabulary = ['kind', 'column', 'source', 'field', 'derivation', 'offset', 'length', 'qualifier', 'component'];

    $this->derivations = ['prefix', 'slice', 'recompose'];

    $this->isBundleTable = fn (mixed $table): bool => is_string($table) && in_array($table, $this->bundleTables, true);

    $this->entryOf = function (string $kind): array {
        $entry = $this->declarations[$kind] ?? null;

        return is_array($entry) ? $entry : [];
    };

    $this->edgesOf = function (string $kind, string $category): array {
        $edges = ($this->entryOf)($kind)[$category] ?? null;

        return is_array($edges) ? array_values(array_filter($edges, 'is_array')) : [];
    };

    $this->groupsOf = function (string $kind): array {
        $groups = ($this->entryOf)($kind)['exclusive'] ?? null;

        return is_array($groups) ? array_values(array_filter($groups, 'is_array')) : [];
    };

    $this->membersOf = function (array $group): array {
        $members = $group['members'] ?? null;

        return is_array($members) ? array_values(array_filter($members, 'is_array')) : [];
    };

    $this->tableOfKind = function (mixed $kind): ?string {
        return is_string($kind) ? ($this->tableForKind[$kind] ?? null) : null;
    };

    $this->declaredColumnsOf = function (string $kind, string $category): array {
        $columns = [];

        foreach (($this->edgesOf)($kind, $category) as $edge) {
            $column = $edge['column'] ?? null;

            if (is_string($column)) {
                $columns[$column] = ($this->tableOfKind)($edge['kind'] ?? null);
            }
        }

        return $columns;
    };

    $this->exclusiveColumnsOf = function (string $kind): array {
        $columns = [];

        foreach (($this->groupsOf)($kind) as $group) {
            foreach (($this->membersOf)($group) as $member) {
                $column = $member['column'] ?? null;

                if (is_string($column)) {
                    $columns[$column] = ($this->tableOfKind)($member['kind'] ?? null);
                }
            }
        }

        return $columns;
    };
});

it('carries a dependency declaration for every artifact kind and for nothing else', function (): void {
    $cases = InstalledArtifactKinds::all();
    $missing = [];

    foreach ($cases as $case) {
        if (!array_key_exists($case->value, $this->declarations)) {
            $missing[] = $case->value;
        }
    }

    $known = array_map(static fn (ArtifactKind $case): string => $case->value, $cases);
    $unexpected = [];

    foreach (array_keys($this->declarations) as $kind) {
        if (!is_string($kind) || !in_array($kind, $known, true)) {
            $unexpected[] = is_string($kind) ? $kind : '?';
        }
    }

    expect($missing)->toBe([])
        ->and($unexpected)->toBe([])
        ->and(count($cases))->toBeGreaterThan(0);
});

it('orders the three categories the same way in every entry and uses only the closed edge vocabulary', function (): void {
    $offenders = [];
    $inspected = 0;
    $kinds = array_map(static fn (ArtifactKind $case): string => $case->value, InstalledArtifactKinds::all());

    foreach ($kinds as $kind) {
        $entry = ($this->entryOf)($kind);

        if (array_keys($entry) !== ['requires', 'optional', 'exclusive']) {
            $offenders[] = "{$kind}: the entry does not carry requires, optional and exclusive in that order";
        }

        foreach (['requires', 'optional'] as $category) {
            foreach (($this->edgesOf)($kind, $category) as $position => $edge) {
                $inspected++;
                $label = "{$kind}.{$category}[{$position}]";

                foreach (array_keys($edge) as $key) {
                    if (!in_array($key, $this->edgeVocabulary, true)) {
                        $offenders[] = "{$label}: key ".(is_string($key) ? $key : '?').' is not part of the edge vocabulary';
                    }
                }

                foreach (['kind', 'column', 'source'] as $required) {
                    if (!array_key_exists($required, $edge)) {
                        $offenders[] = "{$label}: the edge misses the key {$required}";
                    }
                }

                if (!is_string($edge['column'] ?? null)) {
                    $offenders[] = "{$label}: column is not a string";
                }

                if (ArtifactKind::tryFrom((string) ($edge['kind'] ?? '')) === null) {
                    $offenders[] = "{$label}: kind is not an artifact kind";
                }

                $source = $edge['source'] ?? null;

                if (!in_array($source, ['payload', 'key'], true)) {
                    $offenders[] = "{$label}: source is neither payload nor key";

                    continue;
                }

                if ($source === 'payload' && array_key_exists('derivation', $edge)) {
                    $offenders[] = "{$label}: a payload edge must not carry a derivation";
                }

                if ($source === 'key' && array_key_exists('field', $edge)) {
                    $offenders[] = "{$label}: a key edge must not carry a payload field";
                }
            }
        }

        foreach (($this->groupsOf)($kind) as $position => $group) {
            $inspected++;
            $label = "{$kind}.exclusive[{$position}]";

            if (array_keys($group) !== ['constraint', 'members']) {
                $offenders[] = "{$label}: the group does not carry constraint and members in that order";
            }

            if (!is_string($group['constraint'] ?? null)) {
                $offenders[] = "{$label}: constraint is not a string";
            }

            $members = ($this->membersOf)($group);

            if (count($members) < 2) {
                $offenders[] = "{$label}: an exclusive group needs at least two members";
            }

            foreach ($members as $index => $member) {
                if (array_keys($member) !== ['column', 'kind']) {
                    $offenders[] = "{$label}.members[{$index}]: the member does not carry column and kind in that order";
                }

                if (!is_string($member['column'] ?? null)) {
                    $offenders[] = "{$label}.members[{$index}]: column is not a string";
                }

                $memberKind = $member['kind'] ?? null;

                if ($memberKind !== null && ArtifactKind::tryFrom((string) $memberKind) === null) {
                    $offenders[] = "{$label}.members[{$index}]: kind is neither null nor an artifact kind";
                }
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe([])
        ->and($inspected)->toBeGreaterThan(0);
});

it('mirrors every declared edge in the remap of its table and every bundled remap entry in a declared edge', function (): void {
    $offenders = [];
    $inspected = 0;

    foreach (InstalledArtifactKinds::all() as $case) {
        $table = $this->tableForKind[$case->value] ?? null;

        if ($table === null) {
            continue;
        }

        $remap = $this->remapFor[$table] ?? [];

        $declared = [
            ...($this->declaredColumnsOf)($case->value, 'requires'),
            ...($this->declaredColumnsOf)($case->value, 'optional'),
            ...array_filter(($this->exclusiveColumnsOf)($case->value), static fn (?string $parent): bool => $parent !== null),
        ];

        foreach ($declared as $column => $parent) {
            $inspected++;

            if (($remap[$column] ?? null) !== $parent) {
                $offenders[] = "{$table}: the declared edge {$column} => ".($parent ?? '?').' is not mirrored in remap';
            }
        }

        foreach ($remap as $column => $parent) {
            if (!is_string($column) || !($this->isBundleTable)($parent)) {
                continue;
            }

            $inspected++;

            if (!array_key_exists($column, $declared)) {
                $offenders[] = "{$table}: the remap entry {$column} => {$parent} has no declared edge";
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe([])
        ->and($inspected)->toBeGreaterThan(0);
});

it('names a whitelisted payload field on every edge that reads its reference from the payload', function (): void {
    $offenders = [];
    $inspected = 0;

    foreach (InstalledArtifactKinds::all() as $case) {
        $configured = config("engine.config_bundle.fields.{$case->value}");
        $fields = is_array($configured) ? $configured : [];

        foreach (['requires', 'optional'] as $category) {
            foreach (($this->edgesOf)($case->value, $category) as $edge) {
                if (($edge['source'] ?? null) !== 'payload') {
                    continue;
                }

                $inspected++;
                $field = $edge['field'] ?? null;

                if (!is_string($field)) {
                    $offenders[] = "{$case->value}.{$category}: a payload edge misses its field";

                    continue;
                }

                if (!in_array($field, $fields, true)) {
                    $offenders[] = "{$case->value}.{$category}: the payload field {$field} is not part of the bundle whitelist";
                }

                if ($field !== ($edge['column'] ?? null)) {
                    $offenders[] = "{$case->value}.{$category}: the payload field {$field} does not match its column ".(is_string($edge['column'] ?? null) ? $edge['column'] : '?');
                }
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe([])
        ->and($inspected)->toBeGreaterThan(0);
});

it('gives every edge that derives its reference from the business key a derivation with all its parameters', function (): void {
    $offenders = [];
    $inspected = 0;

    foreach (InstalledArtifactKinds::all() as $case) {
        foreach (['requires', 'optional'] as $category) {
            foreach (($this->edgesOf)($case->value, $category) as $edge) {
                if (($edge['source'] ?? null) !== 'key') {
                    continue;
                }

                $inspected++;
                $label = "{$case->value}.{$category}.".(is_string($edge['column'] ?? null) ? $edge['column'] : '?');
                $derivation = $edge['derivation'] ?? null;

                if (!in_array($derivation, $this->derivations, true)) {
                    $offenders[] = "{$label}: the derivation is not part of the closed vocabulary";

                    continue;
                }

                if ($derivation === 'recompose') {
                    $qualifier = $edge['qualifier'] ?? null;

                    if (!is_array($qualifier) || !is_int($qualifier['offset'] ?? null) || !array_key_exists('length', $qualifier)) {
                        $offenders[] = "{$label}: a recomposing edge needs a qualifier with offset and length";
                    }

                    if (!is_int($edge['component'] ?? null)) {
                        $offenders[] = "{$label}: a recomposing edge needs the index of its component";
                    }

                    continue;
                }

                if (!is_int($edge['offset'] ?? null)) {
                    $offenders[] = "{$label}: a {$derivation} edge needs an integer offset";
                }

                if (!array_key_exists('length', $edge) || (!is_int($edge['length']) && $edge['length'] !== null)) {
                    $offenders[] = "{$label}: a {$derivation} edge needs a length of either an integer or null";
                }

                if (array_key_exists('qualifier', $edge) || array_key_exists('component', $edge)) {
                    $offenders[] = "{$label}: only a recomposing edge carries a qualifier or a component";
                }
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe([])
        ->and($inspected)->toBeGreaterThan(0);
});

it('keeps the graph of mandatory dependencies between the artifact kinds free of cycles', function (): void {
    $targets = [];
    $inspected = 0;

    foreach (InstalledArtifactKinds::all() as $case) {
        $reached = [];

        foreach (($this->edgesOf)($case->value, 'requires') as $edge) {
            if (is_string($edge['kind'] ?? null)) {
                $reached[] = $edge['kind'];
                $inspected++;
            }
        }

        foreach (($this->groupsOf)($case->value) as $group) {
            foreach (($this->membersOf)($group) as $member) {
                if (is_string($member['kind'] ?? null)) {
                    $reached[] = $member['kind'];
                    $inspected++;
                }
            }
        }

        $targets[$case->value] = array_values(array_unique($reached));
    }

    $cycles = [];
    $settled = [];

    $walk = function (string $node, array $path) use (&$walk, &$cycles, &$settled, $targets): void {
        if (in_array($node, $path, true)) {
            $cycles[] = implode(' -> ', [...$path, $node]);

            return;
        }

        if (isset($settled[$node])) {
            return;
        }

        foreach ($targets[$node] ?? [] as $target) {
            $walk($target, [...$path, $node]);
        }

        $settled[$node] = true;
    };

    foreach (array_keys($targets) as $node) {
        $walk($node, []);
    }

    $cycles = array_values(array_unique($cycles));

    sort($cycles);

    expect($cycles)->toBe([])
        ->and($inspected)->toBeGreaterThan(0);
});
