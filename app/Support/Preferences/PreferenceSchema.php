<?php

declare(strict_types=1);

namespace App\Support\Preferences;

use App\Enums\Engine\RecordViewMode;
use App\Enums\Preferences\ConfigurationGrid;
use App\Enums\Preferences\GlobalPreference;
use App\Enums\Preferences\GridPreference;
use App\Enums\Preferences\ObjectTypePreference;
use App\Enums\Preferences\PreferenceScope;
use App\Enums\Ui\Appearance;
use App\Enums\Ui\GridDensity;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PreferenceSchema
{
    /** @var list<string> */
    private array $prohibitedRule = ['prohibited'];

    /**
     * @return array<string, mixed>
     */
    public function globalDefaults(): array
    {
        return [
            GlobalPreference::Appearance->value => Appearance::System->value,
            GlobalPreference::Density->value => GridDensity::Compact->value,
            GlobalPreference::PageSize->value => 100,
            GlobalPreference::SidebarOpen->value => true,
            GlobalPreference::StartObjectTypeId->value => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function objectTypeDefaults(): array
    {
        return array_fill_keys(
            array_column(ObjectTypePreference::cases(), 'value'),
            null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function gridDefaults(): array
    {
        return array_fill_keys(
            array_column(GridPreference::cases(), 'value'),
            null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, mixed>>
     */
    public function rules(array $data): array
    {
        $rules = [];

        foreach (PreferenceScope::cases() as $scope) {
            $rules[$scope->value] = ['sometimes', 'array'];
        }

        if (!$this->hasKnownScope($data)) {
            $rules[PreferenceScope::Settings->value] = ['required', 'array'];
        }

        foreach ($this->globalRules() as $key => $rule) {
            $rules[PreferenceScope::Settings->value.'.'.$key] = $rule;
        }

        foreach ($this->objectTypeRules() as $key => $rule) {
            $rules[PreferenceScope::ObjectTypes->value.'.*.'.$key] = $rule;
        }

        foreach ($this->gridRules() as $key => $rule) {
            $rules[PreferenceScope::Grids->value.'.*.'.$key] = $rule;
        }

        return array_merge($rules, $this->prohibitions($data));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function globalRules(): array
    {
        return [
            GlobalPreference::Appearance->value => ['nullable', Rule::enum(Appearance::class)],
            GlobalPreference::Density->value => ['nullable', Rule::enum(GridDensity::class)],
            GlobalPreference::PageSize->value => ['nullable', 'integer', 'min:1', 'max:1000'],
            GlobalPreference::SidebarOpen->value => ['nullable', 'boolean'],
            GlobalPreference::StartObjectTypeId->value => ['nullable', 'ulid'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function objectTypeRules(): array
    {
        return [
            ObjectTypePreference::ViewMode->value => ['nullable', Rule::enum(RecordViewMode::class)],
            ObjectTypePreference::KanbanAxis->value => ['nullable', 'string', 'max:255'],
            ObjectTypePreference::KanbanPipeline->value => ['nullable', 'ulid'],
            ObjectTypePreference::HierarchyOrder->value => ['nullable', 'boolean'],
            ObjectTypePreference::ColumnState->value => ['nullable', 'array'],
            ObjectTypePreference::LastSegmentId->value => ['nullable', 'ulid'],
            ObjectTypePreference::LastFilter->value => ['nullable', 'array'],
            ObjectTypePreference::CollapsedSections->value => ['nullable', 'array'],
            ObjectTypePreference::CollapsedSections->value.'.*' => ['string', 'max:255'],
            ObjectTypePreference::HiddenSections->value => ['nullable', 'array'],
            ObjectTypePreference::HiddenSections->value.'.*' => ['string', 'max:255'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function gridRules(): array
    {
        return [
            GridPreference::ColumnState->value => ['nullable', 'array'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasKnownScope(array $data): bool
    {
        foreach (array_keys($data) as $key) {
            if (PreferenceScope::tryFrom($key) instanceof PreferenceScope) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, mixed>>
     */
    private function prohibitions(array $data): array
    {
        $prohibited = [];

        foreach ($data as $scopeKey => $payload) {
            $scope = PreferenceScope::tryFrom($scopeKey);

            if (!$scope instanceof PreferenceScope) {
                $prohibited[$scopeKey] = $this->prohibitedRule;

                continue;
            }

            if (!is_array($payload)) {
                continue;
            }

            $prohibited = array_merge($prohibited, $this->scopeProhibitions($scope, $payload));
        }

        return $prohibited;
    }

    /**
     * @param  array<mixed, mixed>  $payload
     * @return array<string, array<int, mixed>>
     */
    private function scopeProhibitions(PreferenceScope $scope, array $payload): array
    {
        if ($scope === PreferenceScope::Settings) {
            return $this->unknownKeys($scope->value, $payload, array_keys($this->globalRules()));
        }

        $allowed = $scope === PreferenceScope::ObjectTypes
            ? array_keys($this->objectTypeRules())
            : array_keys($this->gridRules());

        $prohibited = [];

        foreach ($payload as $entryKey => $slice) {
            $path = $scope->value.'.'.$entryKey;

            if (!$this->isKnownEntry($scope, (string) $entryKey)) {
                $prohibited[$path] = $this->prohibitedRule;

                continue;
            }

            if (is_array($slice)) {
                $prohibited = array_merge($prohibited, $this->unknownKeys($path, $slice, $allowed));
            }
        }

        return $prohibited;
    }

    private function isKnownEntry(PreferenceScope $scope, string $entryKey): bool
    {
        return $scope === PreferenceScope::Grids
            ? ConfigurationGrid::tryFrom($entryKey) instanceof ConfigurationGrid
            : Str::isUlid($entryKey);
    }

    /**
     * @param  array<mixed, mixed>  $payload
     * @param  array<int, string>  $allowed
     * @return array<string, array<int, mixed>>
     */
    private function unknownKeys(string $path, array $payload, array $allowed): array
    {
        $prohibited = [];

        foreach (array_keys($payload) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $prohibited[$path.'.'.$key] = $this->prohibitedRule;
            }
        }

        return $prohibited;
    }
}
