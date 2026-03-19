# Critic Review Fixes — nubos/init

## P0-1: patchWebRoutes() Regex robuster machen
- [x] Regex toleranter für Whitespace/Kommentare machen
- [x] Warning bei Nicht-Match statt stilles Überspringen
- [x] Test für Warnung bei abweichendem Format

## P0-2: Test-Stubs für P1/P2 (standalone)
- [x] {{Organization}}MembershipTest.php.stub
- [x] {{Organization}}PolicyTest.php.stub
- [x] {{Organization}}CrudTest.php.stub
- [x] SetCurrent{{Organization}}MiddlewareTest.php.stub
- [x] Verifizieren dass copyOrganizationStubs() die Tests kopiert
- [x] InitCommandTest.php erweitern für P1/P2 Test-Dateien

## P1-1: patchFile() Warnung wenn Replacement nicht greift
- [x] Prüfung nach str_replace() ob Content geändert wurde
- [x] Warning ausgeben wenn nicht
- [x] Test hinzufügen

## P1-2: copyOrganizationStubs() Warnung bei fehlenden Verzeichnissen
- [x] Warning statt stillem return
- [x] Test hinzufügen

## P1-3: TenantAware Property-Validierung
- [x] isset($this->tenantId) Check in middleware()
- [x] RuntimeException mit sprechender Meldung

## P1-4: Middleware-Reihenfolge-Test
- [x] Test in tenant-single-db TenantIsolationTest
- [x] Test in tenant-multi-db DatabaseIsolationTest

## P1-5: TenantDatabaseManager::migrate() Exit-Code prüfen
- [x] Artisan::call() Return-Wert capturen
- [x] RuntimeException bei exitCode !== 0

## P2-1: Duplicate Delete{{Organization}}Action entfernen
- [x] tenant-multi-db Version löschen
- [x] Verifizieren dass P6 weiterhin korrekt generiert (shared/ liefert die Datei)

---

## Epic 1 — Critic Review Bugfixes (Runde 2)

### E-P3-02 (P0): Reserved Subdomain Validierung in CreateTenantAction
- [x] `guardAgainstReservedSubdomain()` in base/ und multi-db/ hinzugefügt
- [x] Prüft Slug-Kandidaten gegen `config('nubos.reserved_subdomains')`
- [x] ValidationException bei Treffer
- [x] Tests: base/ und multi-db/ CreateTenantActionTest.php

### E-P6-05 (P1): Multi-DB BelongsToOrg Pivot-Table Reference
- [x] Dynamischen DB-Prefix entfernt
- [x] {{Organization}}User Pivot-Model mit UsesTenantConnection erstellt
- [x] Trait nutzt `->using()` für korrekte Connection
- [x] DatabaseIsolationTest um Pivot-Connection-Assertion erweitert

### E-P6-06 (P1): Observer async Cleanup via Queue-Job
- [x] CleanupUser{{Organization}}MembershipsJob erstellt (ShouldQueue, try/catch pro Tenant)
- [x] Observer dispatched Job statt synchroner Iteration
- [x] Test prüft Job-Dispatch

### E-P4-03 (P2): PHPDoc für $substructure[0] Routing-Logik
- [x] Erklärender Kommentar in tenant-shared und tenant-multi-db ServiceProvider

### Verifizierung
- [x] Convention-Check: alle geänderten Dateien bestanden
- [x] Pest-Suite: 112 Tests passed (1314 assertions)

---

## Epic 1 — Critic Review Fixes (Runde 3)

### P0 — Kritische Bugs
- [x] Fix 1: CleanupJob `$userId` Type int→string (UUID)
- [x] Fix 2: Pivot Model `$incrementing` entfernt (Option A)
- [x] Fix 3: TenantScope Error Message → `withoutGlobalScope`
- [x] Fix 4: Conditional Schema → separate Multi-DB Migration

### P1 — Convention-Verletzungen
- [x] Fix 5: TenantSeeder unused Import entfernt
- [x] Fix 6: Alle 4 Pivot-Migrationen auf UUID umgestellt
- [x] Fix 7: Vue `is_personal` optional gemacht
- [x] Fix 8: `forceFill` eliminiert, `database` in `$fillable` via InitCommand
- [x] Fix 9: Block-Kommentare entfernt (tenant-shared + tenant-multi-db)

### P2 — Design-Verbesserungen
- [x] Fix 10: TenantAware `$tenantId` nullable mit Default null
- [x] Fix 11: DeleteTenantAction `members()->detach()` vor delete
- [x] Fix 12: Multi-DB migrate Pfad-Check vor Artisan::call

### P3 — Fehlende Tests
- [x] Fix 13: TenantScope RuntimeException Test (inline ScopedItem Model)
- [x] Fix 14: Personal Org Listener Test (standalone)
- [x] Fix 15: Middleware-Ordering Test (tenant-shared)
- [x] Fix 16: Cross-Tenant URL Manipulation Test (single-db IdentifyTenantTest)

### Verifizierung
- [x] `php artisan test` — 112 passed, 1317 assertions
- [x] `./vendor/bin/pint --test packages/nubos/init/stubs/` — pass
- [x] InitCommandTest Assertions an neue Struktur angepasst
- [x] Cleanup-Funktion um `add_database_to_tenants_table` erweitert
