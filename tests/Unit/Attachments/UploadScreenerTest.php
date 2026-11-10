<?php

declare(strict_types=1);

use App\Support\Storage\UploadScreener;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/upload-screener-'.bin2hex(random_bytes(6));
    mkdir($this->directory);

    $this->screener = app(UploadScreener::class);

    /** @var callable(string, string):UploadedFile */
    $this->upload = function (string $name, string $contents): UploadedFile {
        $path = "{$this->directory}/".bin2hex(random_bytes(6));
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    };

    /** @var callable(string, array<string, string>, bool=):UploadedFile */
    $this->archive = function (string $name, array $entries, bool $encrypted = false): UploadedFile {
        $path = "{$this->directory}/".bin2hex(random_bytes(6)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);

            if ($encrypted) {
                $zip->setEncryptionName($entry, ZipArchive::EM_AES_256, 'secret');
            }
        }

        $zip->close();

        return new UploadedFile($path, $name, null, null, true);
    };

    $this->ole = static fn (string $streams): string => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 504).$streams;

    $this->utf16 = static fn (string $text): string => implode('', array_map(static fn (string $char): string => "{$char}\0", str_split($text)));

    /** @var callable(UploadedFile):array<string, list<string>> */
    $this->errorsOf = fn (UploadedFile $file): array => Validator::make(['file' => $file], ['file' => [$this->screener->rule()]])->errors()->toArray();
});

afterEach(function (): void {
    array_map('unlink', glob("{$this->directory}/*") ?: []);
    rmdir($this->directory);
});

it('accepts every ordinary document, sheet, image and archive', function (Closure $file): void {
    expect($this->screener->refusalOf($file($this)))->toBeNull();
})->with([
    'pdf' => [fn (object $test): UploadedFile => ($test->upload)('angebot.pdf', "%PDF-1.4\n%%EOF")],
    'old excel sheet without macros' => [fn (object $test): UploadedFile => ($test->upload)('budget.xls', ($test->ole)(($test->utf16)('Workbook')))],
    'old word document without macros' => [fn (object $test): UploadedFile => ($test->upload)('brief.doc', ($test->ole)(($test->utf16)('WordDocument')))],
    'xlsx' => [fn (object $test): UploadedFile => ($test->archive)('budget.xlsx', ['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<workbook/>'])],
    'odt' => [fn (object $test): UploadedFile => ($test->archive)('brief.odt', ['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<office/>'])],
    'csv' => [fn (object $test): UploadedFile => ($test->upload)('kontakte.csv', "name;mail\nA;a@example.com")],
    'zip of documents' => [fn (object $test): UploadedFile => ($test->archive)('unterlagen.zip', ['lebenslauf.pdf' => '%PDF-1.4', 'zeugnis.docx' => 'x'])],
    'unknown binary' => [fn (object $test): UploadedFile => ($test->upload)('messung.dat', random_bytes(64))],
]);

it('refuses programs, scripts and installers by their name', function (string $name): void {
    expect($this->screener->refusalOf(($this->upload)($name, 'harmless looking text')))
        ->toBe('i18n.backend.support.storage.upload_screener.blocked_type');
})->with(['setup.exe', 'RECHNUNG.PDF.EXE', 'start.bat', 'run.ps1', 'payload.js', 'invoice.vbs', 'link.lnk', 'install.msi', 'app.jar', 'deploy.sh', 'report.docm', 'zahlen.xlsm', 'hilfe.hta']);

it('refuses a program that hides behind a document name', function (): void {
    $program = "MZ\x90\0\x03\0\0\0\x04\0\0\0\xFF\xFF\0\0".str_repeat("\0", 44)."\x80\0\0\0".str_repeat("\0", 64)."PE\0\0L\x01".str_repeat("\0", 200);

    expect($this->screener->refusalOf(($this->upload)('rechnung.pdf', $program)))
        ->toBe('i18n.backend.support.storage.upload_screener.blocked_type');
});

it('refuses office documents that carry macros', function (Closure $file): void {
    expect($this->screener->refusalOf($file($this)))->toBe('i18n.backend.support.storage.upload_screener.macros');
})->with([
    'docx with a vba project' => [fn (object $test): UploadedFile => ($test->archive)('brief.docx', ['[Content_Types].xml' => '<Types/>', 'word/vbaProject.bin' => 'vba'])],
    'xlsx with a vba project' => [fn (object $test): UploadedFile => ($test->archive)('budget.xlsx', ['xl/vbaProject.bin' => 'vba'])],
    'old excel sheet with a vba project' => [fn (object $test): UploadedFile => ($test->upload)('budget.xls', ($test->ole)(($test->utf16)('_VBA_PROJECT_CUR')))],
    'old word document with a vba project' => [fn (object $test): UploadedFile => ($test->upload)('brief.doc', ($test->ole)(($test->utf16)('_VBA_PROJECT')))],
    'odt with basic macros' => [fn (object $test): UploadedFile => ($test->archive)('brief.odt', ['mimetype' => 'application/vnd.oasis.opendocument.text', 'Basic/Standard/Module1.xml' => '<module/>'])],
]);

it('refuses an archive that carries a program', function (): void {
    expect($this->screener->refusalOf(($this->archive)('unterlagen.zip', ['lebenslauf.pdf' => '%PDF-1.4', 'tools/setup.exe' => 'MZ'])))
        ->toBe('i18n.backend.support.storage.upload_screener.blocked_content');
});

it('refuses an encrypted archive because its content cannot be checked', function (): void {
    expect($this->screener->refusalOf(($this->archive)('unterlagen.zip', ['lebenslauf.pdf' => '%PDF-1.4'], true)))
        ->toBe('i18n.backend.support.storage.upload_screener.encrypted');
});

it('reports a refusal as a German validation message on the field', function (): void {
    expect(($this->errorsOf)(($this->upload)('setup.exe', 'x')))->toBe([
        'file' => ['Dieser Dateityp ist aus Sicherheitsgründen nicht erlaubt.'],
    ])
        ->and(($this->errorsOf)(($this->upload)('angebot.pdf', '%PDF-1.4')))->toBe([]);
});

it('names a generated mime as blocked only when it is a program', function (): void {
    expect($this->screener->isBlockedMime('application/x-dosexec'))->toBeTrue()
        ->and($this->screener->isBlockedMime('APPLICATION/X-MSDOWNLOAD'))->toBeTrue()
        ->and($this->screener->isBlockedMime('text/html'))->toBeFalse()
        ->and($this->screener->isBlockedMime('application/pdf'))->toBeFalse();
});

it('no longer narrows record attachments to a fixed list of types', function (): void {
    expect(config('engine.attachments'))->not->toHaveKey('allowed_mimes')
        ->and(config('engine.attachments.blocked_extensions'))->toContain('exe', 'msi', 'ps1', 'docm')
        ->and(config('engine.attachments.blocked_extensions'))->not->toContain('xls', 'doc', 'odt', 'zip');
});
