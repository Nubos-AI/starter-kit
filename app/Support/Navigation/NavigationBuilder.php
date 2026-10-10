<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use App\Enums\Ui\NavIcon;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Engine\ObjectTypeBackingRegistry;
use App\Support\Modules\UiModuleRegistry;
use App\Support\Teams\ActiveTeamUrlDefault;
use Illuminate\Database\Eloquent\Collection;

class NavigationBuilder
{
    private string $teamSegment = '';

    /**
     * @var array<string, bool>
     */
    private array $permissions = [];

    public function __construct(
        private readonly ActiveTeamUrlDefault $urlDefault,
        private readonly ObjectTypeBackingRegistry $backings,
        private readonly UiModuleRegistry $modules,
    ) {}

    /**
     * @param  Collection<int, ObjectType>  $objectTypes
     * @param  array<string, bool>  $permissions
     * @return array<string, mixed>
     */
    public function build(User $user, Collection $objectTypes, array $permissions): array
    {
        $this->permissions = $permissions;

        $segment = $this->urlDefault->current();
        if ($segment === null) {
            return ['main' => [], 'configuration' => []];
        }
        $this->teamSegment = '/'.$segment;

        $permitted = $objectTypes->filter(
            fn (ObjectType $type): bool => $this->allows("{$type->slug}.view"),
        );

        return $this->modules->extendNavigation([
            'main' => $this->mainSections($this->recordChildren($permitted)),
            'configuration' => $this->configuration($user, $permitted->isNotEmpty()),
        ], $user, $this->permissions);
    }

    private function allows(string $ability): bool
    {
        return $this->permissions[$ability] ?? false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function mainSections(array $records): array
    {
        $personal = [
            $this->leaf('dashboard', __('i18n.backend.support.navigation.navigation_builder.dashboard'), NavIcon::LayoutGrid, '/dashboard'),
            $this->leaf('reminders', __('i18n.backend.support.navigation.navigation_builder.reminders'), NavIcon::Clock, '/reminders'),
            $this->leaf('approvals', __('i18n.backend.support.navigation.navigation_builder.my_approvals'), NavIcon::ShieldCheck, '/engine/approvals'),
        ];

        $sections = [
            [
                'label' => '',
                'items' => $personal,
            ],
        ];

        if ($records !== []) {
            $sections[] = [
                'key' => 'records',
                'label' => __('i18n.backend.support.navigation.navigation_builder.records'),
                'items' => $records,
            ];
        }

        return $sections;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function analyticsChildren(): array
    {
        return [
            $this->leaf('reports', __('i18n.backend.support.navigation.navigation_builder.reports'), NavIcon::ChartColumn, '/reports'),
            $this->leaf('dashboards', __('i18n.backend.support.navigation.navigation_builder.dashboards'), NavIcon::LayoutDashboard, '/dashboards'),
            $this->leaf('goals', __('i18n.backend.support.navigation.navigation_builder.goals'), NavIcon::Target, '/goals'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function configuration(User $user, bool $hasVisibleRecords): array
    {
        $groups = [];

        if ($hasVisibleRecords) {
            $groups[] = [...$this->group('cfg-analytics', __('i18n.backend.support.navigation.navigation_builder.analytics'), $this->analyticsChildren()), 'order' => 80];
        }

        $types = [];

        if ($this->allows('object-types.view')) {
            $types[] = $this->leaf('object-types', __('i18n.backend.support.navigation.navigation_builder.object_types'), NavIcon::Settings2, '/engine/object-types');
            $types[] = $this->leaf('relationship-types', __('i18n.backend.support.navigation.navigation_builder.relationship_types'), NavIcon::Waypoints, '/engine/relationship-types');
        }

        if ($types !== []) {
            $groups[] = $this->group('cfg-types', __('i18n.backend.support.navigation.navigation_builder.data_model'), $types);
        }

        $settings = [];

        if ($this->allows('reminder-types.view')) {
            $settings[] = $this->leaf('reminder-types', __('i18n.backend.support.navigation.navigation_builder.reminder_types'), NavIcon::Clock, '/engine/reminder-types');
        }

        if ($this->allows('activity-types.view')) {
            $settings[] = $this->leaf('activity-types', __('i18n.backend.support.navigation.navigation_builder.activity_types'), NavIcon::Clock, '/engine/activity-types');
        }

        if ($this->allows('skills.view')) {
            $settings[] = $this->leaf('skills', __('i18n.backend.support.navigation.navigation_builder.skills'), NavIcon::Award, '/engine/skills');
        }

        $settings[] = $this->leaf('segments', __('i18n.backend.support.navigation.navigation_builder.segments'), NavIcon::Filter, '/engine/segment-management');

        $groups[] = $this->group('cfg-settings', __('i18n.backend.support.navigation.navigation_builder.settings'), $settings);

        $nodes = [$this->parent('configuration', __('i18n.backend.support.navigation.navigation_builder.configuration'), NavIcon::Settings2, $groups)];

        $administration = $this->administrationGroups($user);

        if ($administration !== []) {
            $nodes[] = $this->parent('administration', __('i18n.backend.support.navigation.navigation_builder.administration'), NavIcon::ShieldUser, $administration);
        }

        return $nodes;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function administrationGroups(User $user): array
    {
        $groups = [];

        $access = $this->organizationChildren();

        if ($access !== []) {
            $groups[] = $this->group('adm-access', __('i18n.backend.support.navigation.navigation_builder.access'), $access);
        }

        $operations = [];

        if ($this->allows('config.export') || $this->allows('config.import')) {
            $operations[] = $this->leaf('config-as-code', __('i18n.backend.support.navigation.navigation_builder.configuration'), NavIcon::Database, '/engine/config');
        }

        if ($this->allows('maintenance.manage')) {
            $operations[] = $this->leaf('maintenance', __('i18n.backend.support.navigation.navigation_builder.maintenance_mode'), NavIcon::Wrench, '/engine/maintenance');
        }

        if ($operations !== []) {
            $groups[] = $this->group('adm-operations', __('i18n.backend.support.navigation.navigation_builder.operations'), $operations);
        }

        $interfaces = [];

        if ($user->isEscalatedAuthority()) {
            $interfaces[] = $this->leaf('webhooks', __('i18n.backend.support.navigation.navigation_builder.webhooks'), NavIcon::Webhook, '/engine/webhooks');
        }

        if ($user->isEscalatedAuthority() || $user->hasPermission('api-tokens.manage')) {
            $interfaces[] = $this->leaf('api-tokens', __('i18n.backend.support.navigation.navigation_builder.api_token'), NavIcon::KeyRound, '/engine/api-tokens');
        }

        if ($interfaces !== []) {
            $groups[] = $this->group('adm-interfaces', __('i18n.backend.support.navigation.navigation_builder.integrations'), $interfaces);
        }

        return $groups;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function group(string $key, string $label, array $items): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'group' => true,
            'children' => $items,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function organizationChildren(): array
    {
        $children = [];

        if ($this->allows('members.view')) {
            $children[] = $this->leaf('users', __('i18n.backend.support.navigation.navigation_builder.users'), NavIcon::Users, '/engine/users');
        }

        if ($this->allows('teams.view')) {
            $children[] = $this->leaf('teams', __('i18n.backend.support.navigation.navigation_builder.teams'), NavIcon::Users, '/engine/teams');
        }

        if ($this->allows('roles.view')) {
            $children[] = $this->leaf('roles', __('i18n.backend.support.navigation.navigation_builder.roles'), NavIcon::ShieldCheck, '/engine/roles');
        }

        if ($this->allows('organisation.view')) {
            $children[] = $this->leaf(
                'personalization',
                __('i18n.backend.support.navigation.navigation_builder.personalisation'),
                NavIcon::UserCog,
                '/engine/personalization',
            );
        }

        return $children;
    }

    /**
     * @param  Collection<int, ObjectType>  $permitted
     * @return array<int, array<string, mixed>>
     */
    private function recordChildren(Collection $permitted): array
    {
        return $permitted
            ->filter(fn (ObjectType $type): bool => $type->is_navigable)
            ->sortBy([['nav_position', 'asc'], ['name', 'asc']])
            ->map(fn (ObjectType $type): array => $this->leaf(
                "record-{$type->slug}",
                $type->name,
                $type->nav_icon,
                $this->backings->for($type)->indexPath($type),
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function leaf(string $key, string $label, NavIcon $icon, string $href): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon->value,
            'href' => $this->teamSegment.$href,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function parent(string $key, string $label, NavIcon $icon, array $children): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon->value,
            'children' => $children,
        ];
    }
}
