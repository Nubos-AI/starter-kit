<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->composerHook = 'app/Support/Modules/ModuleComposerScripts.php';

    /** @var callable(string):list<int> */
    $this->processOrGitLines = static function (string $source): array {
        $processFunctions = ['exec', 'passthru', 'pcntl_exec', 'popen', 'proc_open', 'shell_exec', 'system'];
        $memberPrefixes = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => !$token->isIgnorable(),
        ));
        $lines = [];

        foreach ($tokens as $index => $token) {
            $name = ltrim($token->text, '\\');
            $next = $tokens[$index + 1] ?? null;
            $previous = $tokens[$index - 1] ?? null;
            $isName = $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]);

            $spawnsProcess = $token->text === '`'
                || ($isName && preg_match('/(^|\\\\)Process$/', $name) === 1 && $next?->is(T_DOUBLE_COLON) === true)
                || ($isName && str_starts_with($name, 'Symfony\\Component\\Process'))
                || ($isName && $next?->text === '(' && $previous?->is($memberPrefixes) !== true && in_array(strtolower($name), $processFunctions, true));

            if ($spawnsProcess || preg_match('/\\bgit\\b/i', $token->text) === 1) {
                $lines[] = $token->line;
            }
        }

        return array_values(array_unique($lines));
    };

    /** @var callable(list<string>):list<string> */
    $this->processOrGitOffenders = function (array $directories): array {
        $offenders = [];

        foreach ($directories as $directory) {
            foreach (File::allFiles(base_path($directory)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                foreach (($this->processOrGitLines)($file->getContents()) as $line) {
                    $offenders[] = "{$directory}/{$file->getRelativePathname()}:{$line}";
                }
            }
        }

        return $offenders;
    };
});

it('the application never spawns a process or speaks git', function (): void {
    $directories = ['app', 'routes', 'config', 'bootstrap'];

    foreach ($directories as $directory) {
        expect(File::allFiles(base_path($directory)))->not->toBeEmpty("{$directory} holds no file to scan.");
    }

    $offenders = array_values(array_filter(
        ($this->processOrGitOffenders)($directories),
        fn (string $offender): bool => !str_starts_with($offender, "{$this->composerHook}:"),
    ));

    expect($offenders)->toBe([], 'Prozess- oder Git-Aufrufe: '.implode(', ', $offenders));
});

it('exempts only the Composer hook, which starts artisan from inside Composer rather than from the application', function (): void {
    $hookOffenders = array_filter(
        ($this->processOrGitOffenders)(['app/Support/Modules']),
        fn (string $offender): bool => str_starts_with($offender, "{$this->composerHook}:"),
    );

    expect($hookOffenders)->not->toBeEmpty();
});

it('the process and git scan reports a probe that spawns a process or speaks git on its line', function (string $statement): void {
    expect(($this->processOrGitLines)("<?php\n\n{$statement}\n"))->toBe([3]);
})->with([
    'the Process facade' => ["Process::run('ls');"],
    'the fully qualified Process facade' => ["\\Illuminate\\Support\\Facades\\Process::run('ls');"],
    'a symfony process import' => ['use Symfony\\Component\\Process\\Process;'],
    'proc_open' => ["proc_open('ls', [], \$pipes);"],
    'shell_exec' => ["shell_exec('ls');"],
    'exec' => ["exec('ls');"],
    'passthru' => ["passthru('ls');"],
    'popen' => ["popen('ls', 'r');"],
    'system' => ["system('ls');"],
    'the backtick operator' => ['$listing = `ls`;'],
    'git inside a string' => ["\$command = 'git push origin main';"],
    'git as an upper case word' => ["\$command = ['GIT', 'status'];"],
]);

it('look-alikes pass the process and git scan', function (string $statement): void {
    expect(($this->processOrGitLines)("<?php\n\n{$statement}\n"))->toBe([]);
})->with([
    'a model whose name ends in Process' => ['ApprovalProcess::query();'],
    'a method that shares a process function name' => ["\$statement->exec('select 1');"],
    'words that merely contain git' => ["\$label = 'digit github legitimate';"],
    'git only inside a comment' => ["// git push\n\$label = 'ok';"],
]);
