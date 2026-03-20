# Doku-Plan: StarterKit Documentation

## Struktur-Änderungen

- `tenants/` wird zu `organizations/` (Rename)
- `features.md` wird aufgelöst → Inhalte gehen in `architecture/*`
- `concept-overview.md` wird aufgelöst → Inhalte gehen in `organizations/overview.md` + `init-command.md`
- `identity-pro.md` ist kaputt (enthält Introduction-Content statt Identity Pro)

## Dateien

### Root-Level

| Datei | Status | Grund |
|---|---|---|
| `index.md` | SKIP | VitePress config, kein Content |
| `introduction.md` | UPDATE | Code hat sich geändert (neuer Init-Command, neue Enums) |
| `installation.md` | UPDATE | Muss `nubos:init` korrekt referenzieren |
| `init-command.md` | CREATE | Neue Datei für den `nubos:init` Wizard (NubosInitCommand.php) |
| `configuration.md` | UPDATE | Config-Keys haben sich geändert (sub_organization statt sub_team) |
| `project-structure.md` | UPDATE | Muss neue Stub-Struktur reflektieren |

### organizations/ (vorher tenants/)

| Datei | Status | Grund |
|---|---|---|
| `organizations/overview.md` | CREATE | Ersetzt `concept-overview.md`, fokussiert auf Organization-Wahl |
| `organizations/team.md` | UPDATE | Existiert als `tenants/team.md`, Code aktualisiert (HasTeams trait hat tenant-aware check) |
| `organizations/workspace.md` | UPDATE | Existiert als `tenants/workspace.md`, muss standalone workspace dokumentieren |
| `organizations/workspace-with-teams.md` | CREATE | Workspace+Teams Hierarchie (workspace-teams stubs) |
| `organizations/tenant-single-db.md` | CREATE | Split aus `tenants/tenant.md` — nur Single-DB |
| `organizations/tenant-multi-db.md` | CREATE | Split aus `tenants/tenant.md` — nur Multi-DB |
| `organizations/tenant-with-sub-orgs.md` | CREATE | Tenant + Sub-Structures (Teams/Workspaces/Workspace+Teams) |

### architecture/

| Datei | Status | Grund |
|---|---|---|
| `architecture/routing.md` | CREATE | URL-based routing aus features.md extrahiert + erweitert |
| `architecture/middleware.md` | CREATE | SetCurrent, RedirectToCurrent, TenantIdentification |
| `architecture/actions.md` | CREATE | Action-Pattern mit DB::transaction und Events |
| `architecture/events.md` | CREATE | Event-Klassen und Dispatching |
| `architecture/models-and-traits.md` | CREATE | UUID, SoftDeletes, HasTeams, BelongsToTenant etc. |
| `architecture/migrations.md` | CREATE | Migration-Nummern, Schema-Konventionen |
| `architecture/testing.md` | CREATE | Pest tests, PHPStan, Pint |

### multi-tenancy/

| Datei | Status | Grund |
|---|---|---|
| `multi-tenancy/overview.md` | CREATE | Single-DB vs Multi-DB Entscheidung |
| `multi-tenancy/tenant-scope.md` | CREATE | TenantScope trait (Global Scope, auto-set tenant_id) |
| `multi-tenancy/data-isolation.md` | CREATE | Wie Daten isoliert werden |
| `multi-tenancy/subdomain-routing.md` | CREATE | TenantIdentification middleware, Domain model |
| `multi-tenancy/multi-database.md` | CREATE | HasTenantDatabase, ConfigureTenantDatabaseAction |
| `multi-tenancy/queue-jobs.md` | CREATE | TenantAware trait, TenantAwareJob middleware |

### modules/

| Datei | Status | Grund |
|---|---|---|
| `modules/overview.md` | SKIP | Unchanged |
| `modules/how-modules-work.md` | SKIP | Unchanged |
| `modules/identity-pro.md` | UPDATE | Kaputt — enthält Introduction-Content statt Identity Pro |
| `modules/sso-oidc.md` | SKIP | Placeholder, kein Code |
| `modules/sso-saml.md` | SKIP | Placeholder, kein Code |
| `modules/scim.md` | SKIP | Placeholder, kein Code |
| `modules/integrations-pack.md` | SKIP | Placeholder, kein Code |
| `modules/compliance-pack.md` | SKIP | Placeholder, kein Code |
| `modules/suite-container.md` | SKIP | Placeholder, kein Code |

### extending/

| Datei | Status | Grund |
|---|---|---|
| `extending/overview.md` | PLACEHOLDER | Kein Code, nur Übersichtsseite |
| `extending/custom-models.md` | PLACEHOLDER | Kein Code |
| `extending/custom-actions.md` | PLACEHOLDER | Kein Code |
| `extending/overriding-module-views.md` | PLACEHOLDER | Erwähnt in intro, aber kein Code |
| `extending/adding-permissions.md` | PLACEHOLDER | Kein Code (Identity Pro) |
| `extending/feature-flags.md` | PLACEHOLDER | Erwähnt in intro, aber kein Code |

### Zu löschende Dateien

| Datei | Grund |
|---|---|
| `features.md` | Inhalte verteilt auf architecture/* |
| `concept-overview.md` | Inhalte verteilt auf organizations/overview.md + init-command.md |
| `tenants/team.md` | Verschoben nach organizations/team.md |
| `tenants/workspace.md` | Verschoben nach organizations/workspace.md |
| `tenants/tenant.md` | Aufgeteilt in organizations/tenant-single-db.md + tenant-multi-db.md |

## Zusammenfassung

| Status | Anzahl |
|---|---|
| CREATE | 20 |
| UPDATE | 6 |
| SKIP | 8 |
| PLACEHOLDER | 6 |
| DELETE | 5 |
| **Gesamt** | **45** |

## Schwarm-Reihenfolge

Nur CREATE und UPDATE brauchen den Schwarm (3 Subagents pro Datei).

**Schwarm-Dateien (26 Stück):**
1. introduction.md (UPDATE)
2. installation.md (UPDATE)
3. init-command.md (CREATE)
4. configuration.md (UPDATE)
5. project-structure.md (UPDATE)
6. organizations/overview.md (CREATE)
7. organizations/team.md (UPDATE)
8. organizations/workspace.md (UPDATE)
9. organizations/workspace-with-teams.md (CREATE)
10. organizations/tenant-single-db.md (CREATE)
11. organizations/tenant-multi-db.md (CREATE)
12. organizations/tenant-with-sub-orgs.md (CREATE)
13. architecture/routing.md (CREATE)
14. architecture/middleware.md (CREATE)
15. architecture/actions.md (CREATE)
16. architecture/events.md (CREATE)
17. architecture/models-and-traits.md (CREATE)
18. architecture/migrations.md (CREATE)
19. architecture/testing.md (CREATE)
20. multi-tenancy/overview.md (CREATE)
21. multi-tenancy/tenant-scope.md (CREATE)
22. multi-tenancy/data-isolation.md (CREATE)
23. multi-tenancy/subdomain-routing.md (CREATE)
24. multi-tenancy/multi-database.md (CREATE)
25. multi-tenancy/queue-jobs.md (CREATE)
26. modules/identity-pro.md (UPDATE)
