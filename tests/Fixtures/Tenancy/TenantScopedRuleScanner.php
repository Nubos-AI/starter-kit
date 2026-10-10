<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tenancy;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Finder\Finder;

class TenantScopedRuleScanner
{
    /** @var list<string> */
    private array $modelLessTables = ['role_permission'];

    /** @var list<string> */
    private array $foreignKeyColumnMethods = ['foreignulid', 'foreignuuid', 'foreignid', 'ulid'];

    /** @var list<string> */
    private array $requestSourceMethods = ['input', 'get', 'post', 'query', 'string', 'str', 'integer'];

    /** @var list<string> */
    private array $inputArrayVariables = ['input', 'data'];

    /** @var list<string> */
    private array $scalarParameterTypes = ['string', 'int'];

    /** @var list<string> */
    private array $sinkMethods = ['create', 'forcecreate', 'fill', 'forcefill', 'update', 'updateorcreate', 'firstorcreate', 'insert', 'upsert'];

    /** @var list<string> */
    private array $scopeBypassMethods = ['withoutglobalscope', 'withoutglobalscopes', 'withouttenantscope'];

    /**
     * @return list<string>
     */
    public function guardedTables(string $modelsDirectory): array
    {
        $tables = $this->modelLessTables;

        foreach ($this->parseSources($this->readSources($modelsDirectory, $modelsDirectory)) as $statements) {
            foreach ((new NodeFinder)->findInstanceOf($statements, Class_::class) as $class) {
                $className = $class->namespacedName?->toString();

                if ($className === null || !$this->isTenantScoped($className)) {
                    continue;
                }

                $table = $this->tableOfModel($className);

                if ($table !== null) {
                    $tables[] = $table;
                }
            }
        }

        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }

    /**
     * @param  list<string>  $guardedTables
     * @return list<string>
     */
    public function guardedForeignKeys(string $migrationsDirectory, array $guardedTables): array
    {
        $baseKeys = array_map(static fn (string $table): string => Str::singular($table).'_id', $guardedTables);
        $columns = [];

        foreach ($this->parseSources($this->readSources($migrationsDirectory, $migrationsDirectory)) as $statements) {
            foreach ((new NodeFinder)->findInstanceOf($statements, MethodCall::class) as $call) {
                $column = $this->stringArgument($call, 0);

                if ($column === null || !str_ends_with($column, '_id')) {
                    continue;
                }

                $columns[] = $column;

                if (in_array($this->referencedTable($call, $column), $guardedTables, true)) {
                    $baseKeys[] = $column;
                }
            }
        }

        $keys = $baseKeys;

        foreach (array_unique($columns) as $column) {
            foreach ($baseKeys as $baseKey) {
                if (str_ends_with($column, "_{$baseKey}")) {
                    $keys[] = $column;
                }
            }
        }

        $keys = array_values(array_diff(array_unique($keys), ['tenant_id']));
        sort($keys);

        return $keys;
    }

    /**
     * @return array<string, string>
     */
    public function sourcesIn(string $root, string $subdirectory): array
    {
        return $this->readSources("{$root}/{$subdirectory}", $subdirectory);
    }

    /**
     * @param  array<string, string>  $sources
     * @param  list<string>  $guardedTables
     * @return list<array{path: string, line: int, table: ?string, qualified: bool}>
     */
    public function ruleSites(array $sources, array $guardedTables): array
    {
        $sites = [];

        foreach ($this->parseSources($sources) as $path => $statements) {
            foreach ($this->ruleCandidates($statements, $guardedTables) as $candidate) {
                $sites[] = [
                    'path' => $path,
                    'line' => $candidate['line'],
                    'table' => $candidate['table'],
                    'qualified' => $candidate['qualified'],
                ];
            }
        }

        return $sites;
    }

    /**
     * @param  array<string, string>  $sources
     * @param  list<string>  $guardedTables
     * @param  list<string>  $foreignKeys
     * @return list<array{id: string, path: string, line: int, method: string, key: string}>
     */
    public function writePathFindings(array $sources, array $guardedTables, array $foreignKeys): array
    {
        $findings = [];

        foreach ($this->parseSources($sources) as $path => $statements) {
            foreach ((new NodeFinder)->findInstanceOf($statements, ClassMethod::class) as $method) {
                $methodName = $method->name->toString();
                $taint = $this->taintedVariables($path, $method, $foreignKeys);

                foreach ($this->sinkEntries($method, $taint, $foreignKeys) as $entry) {
                    $id = "{$path}::{$methodName}::{$entry['key']}";

                    if (isset($findings[$id]) || $this->isCarried($method, $entry, $taint, $guardedTables, $foreignKeys)) {
                        continue;
                    }

                    $findings[$id] = [
                        'id' => $id,
                        'path' => $path,
                        'line' => $entry['line'],
                        'method' => $methodName,
                        'key' => $entry['key'],
                    ];
                }
            }
        }

        return array_values($findings);
    }

    /**
     * @param  list<array{id: string, path: string, line: int, method: string, key: string}>  $findings
     * @param  list<array{id: string, carrier: string, reason: string}>  $exemptions
     * @param  array<string, string>  $sources
     * @return array{unlisted: list<string>, orphaned: list<string>, unproven: list<string>}
     */
    public function exemptionViolations(array $findings, array $exemptions, array $sources): array
    {
        $findingIds = array_values(array_unique(array_column($findings, 'id')));
        $exemptionIds = array_column($exemptions, 'id');
        $unproven = [];

        foreach ($exemptions as $exemption) {
            if (in_array($exemption['id'], $findingIds, true) && !$this->isProven($exemption, $sources)) {
                $unproven[] = $exemption['id'];
            }
        }

        return [
            'unlisted' => array_values(array_diff($findingIds, $exemptionIds)),
            'orphaned' => array_values(array_diff($exemptionIds, $findingIds)),
            'unproven' => $unproven,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function readSources(string $directory, string $keyPrefix): array
    {
        $sources = [];

        foreach (Finder::create()->files()->in($directory)->name('*.php')->sortByName() as $file) {
            $code = file_get_contents($file->getPathname());

            if ($code === false) {
                throw new RuntimeException("Unable to read {$file->getPathname()}.");
            }

            $relativePath = str_replace('\\', '/', $file->getRelativePathname());
            $sources["{$keyPrefix}/{$relativePath}"] = $code;
        }

        return $sources;
    }

    /**
     * @param  array<string, string>  $sources
     * @return array<string, array<Node>>
     *
     * @throws RuntimeException
     */
    private function parseSources(array $sources): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $parsed = [];

        foreach ($sources as $path => $code) {
            try {
                $statements = $parser->parse($code);
            } catch (ParserError $error) {
                throw new RuntimeException("Unable to parse {$path}: {$error->getMessage()}", 0, $error);
            }

            if ($statements === null) {
                throw new RuntimeException("Unable to parse {$path}.");
            }

            $parsed[$path] = (new NodeTraverser(new NameResolver, new ParentConnectingVisitor))->traverse($statements);
        }

        return $parsed;
    }

    private function isTenantScoped(string $class): bool
    {
        if (!class_exists($class) || !is_subclass_of($class, Model::class)) {
            return false;
        }

        return in_array(TenantScope::class, $class::resolveGlobalScopeAttributes(), true);
    }

    private function tableOfModel(string $class): ?string
    {
        if (!class_exists($class) || !is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
            return null;
        }

        return (new $class)->getTable();
    }

    private function referencedTable(MethodCall $call, string $column): ?string
    {
        $method = $this->methodName($call);

        foreach ($this->chainAbove($call) as $link) {
            $linkName = $this->methodName($link);

            if (in_array($method, $this->foreignKeyColumnMethods, true) && $linkName === 'constrained') {
                return $this->stringArgument($link, 0) ?? Str::plural(Str::beforeLast($column, '_id'));
            }

            if ($method === 'foreign' && $linkName === 'on') {
                return $this->stringArgument($link, 0);
            }
        }

        return null;
    }

    /**
     * @param  Node|array<Node>  $nodes
     * @param  list<string>  $guardedTables
     * @return list<array{line: int, table: ?string, qualified: bool}>
     */
    private function ruleCandidates(Node|array $nodes, array $guardedTables): array
    {
        $candidates = [];

        foreach ((new NodeFinder)->find($nodes, static fn (Node $node): bool => true) as $node) {
            $candidate = $this->ruleCandidate($node);

            if ($candidate === null || ($candidate['table'] !== null && !in_array($candidate['table'], $guardedTables, true))) {
                continue;
            }

            $candidates[] = ['line' => $node->getStartLine(), ...$candidate];
        }

        return $candidates;
    }

    /**
     * @return array{table: ?string, qualified: bool}|null
     */
    private function ruleCandidate(Node $node): ?array
    {
        if ($node instanceof String_ || $node instanceof InterpolatedStringPart) {
            if (preg_match('/(?:^|\|)\s*exists:([^,|]*)/i', $node->value, $match) !== 1) {
                return null;
            }

            return ['table' => $this->tableName($match[1]), 'qualified' => false];
        }

        if ($node instanceof StaticCall && $node->class instanceof Name && $node->class->toString() === Rule::class && $this->methodName($node) === 'exists') {
            return ['table' => $this->argumentTable($node), 'qualified' => $this->isQualified($node)];
        }

        if ($node instanceof New_ && $node->class instanceof Name && $node->class->toString() === Exists::class) {
            return ['table' => $this->argumentTable($node), 'qualified' => $this->isQualified($node)];
        }

        return null;
    }

    private function argumentTable(StaticCall|New_ $call): ?string
    {
        $table = $this->argumentValue($call, 0);

        if ($table instanceof String_) {
            return $this->tableName($table->value);
        }

        if ($table instanceof ClassConstFetch && $table->class instanceof Name && $table->name instanceof Identifier && $table->name->toLowerString() === 'class') {
            return $this->tableOfModel($table->class->toString());
        }

        return null;
    }

    private function tableName(string $reference): ?string
    {
        $reference = trim($reference);
        $withoutConnection = str_contains($reference, '.') ? Str::after($reference, '.') : $reference;

        if (str_contains($withoutConnection, '\\')) {
            return $this->tableOfModel(ltrim($withoutConnection, '\\'));
        }

        $table = Str::afterLast($reference, '.');

        return $table === '' ? null : $table;
    }

    private function isQualified(Expr $candidate): bool
    {
        foreach ($this->chainAbove($candidate) as $link) {
            if ($this->isTenantCondition($link) || $this->isTenantClosure($link)) {
                return true;
            }
        }

        return false;
    }

    private function isTenantCondition(MethodCall $call): bool
    {
        $column = $this->stringArgument($call, 0);

        if ($this->methodName($call) !== 'where' || $column === null || ($column !== 'tenant_id' && !str_ends_with($column, '.tenant_id'))) {
            return false;
        }

        $second = $this->argumentValue($call, 1);
        $third = $this->argumentValue($call, 2);

        return match (count($call->args)) {
            2 => $second !== null && !$this->isNull($second) && !$second instanceof Array_,
            3 => $second instanceof String_ && $second->value === '=' && $third !== null && !$this->isNull($third) && !$third instanceof Array_,
            default => false,
        };
    }

    private function isTenantClosure(MethodCall $call): bool
    {
        $callback = $this->argumentValue($call, 0);

        if (!in_array($this->methodName($call), ['where', 'using'], true) || (!$callback instanceof Closure && !$callback instanceof ArrowFunction)) {
            return false;
        }

        $hasTenantCondition = false;

        foreach ((new NodeFinder)->findInstanceOf($callback, MethodCall::class) as $inner) {
            if (str_starts_with((string) $this->methodName($inner), 'orwhere')) {
                return false;
            }

            $hasTenantCondition = $hasTenantCondition || $this->isTenantCondition($inner);
        }

        return $hasTenantCondition;
    }

    private function isNull(Expr $expression): bool
    {
        return $expression instanceof ConstFetch && $expression->name->toLowerString() === 'null';
    }

    /**
     * @param  list<string>  $foreignKeys
     * @return array<string, list<string>>
     */
    private function taintedVariables(string $path, ClassMethod $method, array $foreignKeys): array
    {
        $taint = [];

        if (preg_match('~^(?:app|packages/[^/]+/[^/]+/src)/Actions/~', $path) === 1 && $method->name->toLowerString() === 'execute') {
            foreach ($method->params as $parameter) {
                $type = $parameter->type instanceof NullableType ? $parameter->type->type : $parameter->type;

                if (!$type instanceof Identifier || !in_array($type->toLowerString(), $this->scalarParameterTypes, true) || !$parameter->var instanceof Variable || !is_string($parameter->var->name)) {
                    continue;
                }

                $key = $this->guardedKey($parameter->var->name, $foreignKeys);

                if ($key !== null) {
                    $taint[$parameter->var->name] = [$key];
                }
            }
        }

        $assignments = (new NodeFinder)->find($method->stmts ?? [], static fn (Node $node): bool => $node instanceof Assign || $node instanceof AssignOp);

        do {
            $changed = false;

            foreach ($assignments as $assignment) {
                if ((!$assignment instanceof Assign && !$assignment instanceof AssignOp) || !$assignment->var instanceof Variable || !is_string($assignment->var->name)) {
                    continue;
                }

                $name = $assignment->var->name;
                $known = $taint[$name] ?? [];
                $merged = array_values(array_unique([...$known, ...$this->marksIn($assignment->expr, $taint, $foreignKeys)]));

                if (count($merged) > count($known)) {
                    $taint[$name] = $merged;
                    $changed = true;
                }
            }
        } while ($changed);

        return $taint;
    }

    /**
     * @param  array<string, list<string>>  $taint
     * @param  list<string>  $foreignKeys
     * @return list<string>
     */
    private function marksIn(Node $node, array $taint, array $foreignKeys): array
    {
        $marks = [];

        foreach ((new NodeFinder)->find($node, static fn (Node $inner): bool => true) as $inner) {
            $source = $this->sourceKey($inner, $foreignKeys);

            if ($source !== null) {
                $marks[] = $source;
            }

            if ($inner instanceof Variable && is_string($inner->name)) {
                array_push($marks, ...($taint[$inner->name] ?? []));
            }
        }

        return array_values(array_unique($marks));
    }

    /**
     * @param  list<string>  $foreignKeys
     */
    private function sourceKey(Node $node, array $foreignKeys): ?string
    {
        if ($node instanceof MethodCall && $node->var instanceof Variable && $node->var->name === 'request') {
            $method = $this->methodName($node);
            $key = $this->stringArgument($node, 0);

            if ($method === 'all') {
                return '*';
            }

            return in_array($method, $this->requestSourceMethods, true) && $key !== null ? $this->guardedKey($key, $foreignKeys) : null;
        }

        if ($node instanceof ArrayDimFetch && $node->var instanceof Variable && in_array($node->var->name, $this->inputArrayVariables, true) && $node->dim instanceof String_) {
            return $this->guardedKey($node->dim->value, $foreignKeys);
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $taint
     * @param  list<string>  $foreignKeys
     * @return list<array{line: int, key: string, marks: list<string>}>
     */
    private function sinkEntries(ClassMethod $method, array $taint, array $foreignKeys): array
    {
        $statements = $method->stmts ?? [];
        $savesModel = (new NodeFinder)->findFirst($statements, fn (Node $node): bool => $node instanceof MethodCall && $this->methodName($node) === 'save') !== null;
        $entries = [];

        $nodes = (new NodeFinder)->find($statements, static fn (Node $node): bool => $node instanceof MethodCall || $node instanceof StaticCall || $node instanceof Assign);
        /** @var list<MethodCall|StaticCall|Assign> $nodes */
        foreach ($nodes as $node) {
            if ($node instanceof Assign) {
                if ($savesModel && $node->var instanceof PropertyFetch && $node->var->name instanceof Identifier) {
                    $entries[] = $this->sinkEntry($node->var->name->toString(), $node->expr, $taint, $foreignKeys);
                }

                continue;
            }

            if (!in_array($this->methodName($node), $this->sinkMethods, true)) {
                continue;
            }

            foreach ($node->args as $argument) {
                if (!$argument instanceof Arg) {
                    continue;
                }

                if ($argument->value instanceof Array_) {
                    foreach ($argument->value->items as $item) {
                        if ($item->key instanceof String_) {
                            $entries[] = $this->sinkEntry($item->key->value, $item->value, $taint, $foreignKeys);
                        }
                    }

                    continue;
                }

                if (in_array('*', $this->marksIn($argument->value, $taint, $foreignKeys), true)) {
                    $entries[] = ['line' => $argument->getStartLine(), 'key' => '*', 'marks' => ['*']];
                }
            }
        }

        return array_values(array_filter($entries));
    }

    /**
     * @param  array<string, list<string>>  $taint
     * @param  list<string>  $foreignKeys
     * @return array{line: int, key: string, marks: list<string>}|null
     */
    private function sinkEntry(string $rawKey, Expr $value, array $taint, array $foreignKeys): ?array
    {
        $key = $this->guardedKey($rawKey, $foreignKeys);
        $marks = $key === null ? [] : $this->marksIn($value, $taint, $foreignKeys);

        if ($key === null || $marks === []) {
            return null;
        }

        return ['line' => $value->getStartLine(), 'key' => $key, 'marks' => $marks];
    }

    /**
     * @param  array{line: int, key: string, marks: list<string>}  $entry
     * @param  array<string, list<string>>  $taint
     * @param  list<string>  $guardedTables
     * @param  list<string>  $foreignKeys
     */
    private function isCarried(ClassMethod $method, array $entry, array $taint, array $guardedTables, array $foreignKeys): bool
    {
        return $this->isCarriedByRule($method, $entry['key'], $guardedTables)
            || $this->isCarriedByResolution($method, $entry['marks'], $taint, $foreignKeys);
    }

    /**
     * @param  list<string>  $guardedTables
     */
    private function isCarriedByRule(ClassMethod $method, string $key, array $guardedTables): bool
    {
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], ArrayItem::class) as $item) {
            if (!$item->key instanceof String_ || $this->normalizedKey($item->key->value) !== $key) {
                continue;
            }

            foreach ($this->ruleCandidates($item->value, $guardedTables) as $candidate) {
                if ($candidate['qualified']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $marks
     * @param  array<string, list<string>>  $taint
     * @param  list<string>  $foreignKeys
     */
    private function isCarriedByResolution(ClassMethod $method, array $marks, array $taint, array $foreignKeys): bool
    {
        $queries = (new NodeFinder)->find($method->stmts ?? [], fn (Node $node): bool => $node instanceof StaticCall
            && $node->class instanceof Name
            && $this->methodName($node) === 'query'
            && $this->isTenantScoped($node->class->toString()));
        /** @var list<StaticCall> $queries */
        foreach ($queries as $query) {
            $chain = [$query, ...$this->chainAbove($query)];
            $names = array_map(fn (StaticCall|MethodCall $link): string => (string) $this->methodName($link), $chain);
            $requalifies = array_filter($chain, fn (StaticCall|MethodCall $link): bool => $link instanceof MethodCall && $this->isTenantCondition($link)) !== [];

            if (array_intersect($names, $this->sinkMethods) !== [] || (array_intersect($names, $this->scopeBypassMethods) !== [] && !$requalifies)) {
                continue;
            }

            foreach ($chain as $link) {
                foreach ($link->args as $argument) {
                    if ($argument instanceof Arg && array_intersect($marks, $this->marksIn($argument->value, $taint, $foreignKeys)) !== []) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param  array{id: string, carrier: string, reason: string}  $exemption
     * @param  array<string, string>  $sources
     */
    private function isProven(array $exemption, array $sources): bool
    {
        if (trim($exemption['reason']) === '' || preg_match('/^((?:app|packages\/[^\/]+\/[^\/]+\/src)\/.+\.php):(\d+)(?:-(\d+))?$/', $exemption['carrier'], $match) !== 1) {
            return false;
        }

        $code = $sources[$match[1]] ?? null;
        $start = (int) $match[2];
        $end = ($match[3] ?? '') === '' ? $start : (int) $match[3];

        if ($code === null || $start < 1 || $end < $start) {
            return false;
        }

        $lines = explode("\n", $code);

        if ($end > count($lines)) {
            return false;
        }

        foreach (array_slice($lines, $start - 1, $end - $start + 1) as $line) {
            if (str_contains($line, '::query()') || str_contains($line, 'tenant_id')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<MethodCall>
     */
    private function chainAbove(Expr $node): array
    {
        $chain = [];
        $current = $node;
        $parent = $current->getAttribute('parent');

        while ($parent instanceof MethodCall && $parent->var === $current) {
            $chain[] = $parent;
            $current = $parent;
            $parent = $current->getAttribute('parent');
        }

        return $chain;
    }

    private function methodName(MethodCall|StaticCall $call): ?string
    {
        return $call->name instanceof Identifier ? $call->name->toLowerString() : null;
    }

    private function argumentValue(MethodCall|StaticCall|New_ $call, int $index): ?Expr
    {
        $argument = $call->args[$index] ?? null;

        return $argument instanceof Arg ? $argument->value : null;
    }

    private function stringArgument(MethodCall|StaticCall|New_ $call, int $index): ?string
    {
        $value = $this->argumentValue($call, $index);

        return $value instanceof String_ ? $value->value : null;
    }

    /**
     * @param  list<string>  $foreignKeys
     */
    private function guardedKey(string $key, array $foreignKeys): ?string
    {
        $normalized = $this->normalizedKey($key);

        return in_array($normalized, $foreignKeys, true) ? $normalized : null;
    }

    private function normalizedKey(string $key): string
    {
        return Str::replaceEnd('_ids', '_id', Str::snake(Str::replaceEnd('.*', '', $key)));
    }
}
