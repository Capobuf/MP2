<?php

namespace App\BusinessBackup;

use App\BusinessBackup\V1\BusinessBackupValidator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class BusinessBackupBundle
{
    public const VERSION = 1;

    /** @param array<string, mixed> $package */
    public function build(array $package, string $workbookPath, string $destination, bool $logo, bool $files): void
    {
        $zip = new ZipArchive;
        $temporary = [];
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Impossibile creare il package ZIP.');
        }
        try {
            $manifest = [
                'bundle_version' => self::VERSION, 'data_format_version' => 3,
                'package_id' => $package['package_id'], 'source_tenant_uuid' => $package['source_tenant_uuid'],
                'exported_at' => $package['exported_at'], 'application_revision' => (string) config('app.revision', ''),
                'company_name' => $package['company']['name'], 'company_timezone' => $package['company']['timezone'],
                'data' => ['path' => 'data.xlsx', 'size' => filesize($workbookPath), 'sha256' => hash_file('sha256', $workbookPath)],
                'included' => ['logo' => $logo, 'files' => $files], 'files' => [],
            ];
            $zip->addFile($workbookPath, 'data.xlsx');
            foreach ($package['file_sources'] as $source) {
                if (($source['kind'] === 'logo' && ! $logo) || ($source['kind'] !== 'logo' && ! $files)) {
                    continue;
                }
                try {
                    $stream = Storage::disk($source['disk'])->readStream($source['path']);
                } catch (\Throwable $exception) {
                    throw new \RuntimeException('File del backup mancante o non leggibile.', previous: $exception);
                }
                if (! is_resource($stream)) {
                    throw new \RuntimeException('File del backup mancante o non leggibile.');
                }
                $path = $this->temporaryFile();
                $temporary[] = $path;
                $output = fopen($path, 'wb');
                try {
                    $size = stream_copy_to_stream($stream, $output);
                } finally {
                    fclose($stream);
                    fclose($output);
                }
                $sha = hash_file('sha256', $path);
                if (($source['size'] !== null && $size !== $source['size']) || ($source['sha256'] !== null && ! hash_equals($source['sha256'], $sha))) {
                    throw new \RuntimeException('Un file del backup è cambiato: size o SHA-256 non coincidono.');
                }
                $folder = match ($source['kind']) {
                    'logo' => 'logo', 'attachment' => 'attachments', 'budget_evidence' => 'budget-evidence',
                    default => throw new \UnexpectedValueException('Unknown file kind.'),
                };
                $entry = 'files/'.$folder.'/'.$source['ref'];
                $manifest['files'][] = ['kind' => $source['kind'], 'ref' => $source['ref'], 'path' => $entry, 'media_type' => $source['media_type'], 'size' => $size, 'sha256' => $sha];
                $zip->addFile($path, $entry);
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (! $zip->close()) {
                throw new \RuntimeException('Scrittura del package ZIP non riuscita.');
            }
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($destination);
            throw $exception;
        } finally {
            foreach ($temporary as $path) {
                @unlink($path);
            }
        }
    }

    /** @return array<string, mixed> */
    public function validate(string $path, string $extension): array
    {
        if ($extension === 'xlsx') {
            return app(BusinessBackupValidator::class)->validate($path) + ['upload_sha256' => hash_file('sha256', $path), 'package_size' => filesize($path)];
        }
        $this->assert($extension === 'zip', 'Caricare un XLSX legacy oppure un package ZIP MP2.');
        $zip = new ZipArchive;
        $this->assert($zip->open($path) === true, 'Package ZIP non leggibile.');
        $workbook = null;
        try {
            $entries = $this->inspectEntries($zip);
            $this->assert(isset($entries['manifest.json'], $entries['data.xlsx']), 'Manifest o data.xlsx mancante.');
            $this->limit($entries['manifest.json']['size'], 'max_manifest_bytes');
            $this->limit($entries['data.xlsx']['size'], 'max_workbook_bytes');
            $manifestBytes = $zip->getFromName('manifest.json', $entries['manifest.json']['size'] + 1);
            $this->assert(is_string($manifestBytes) && strlen($manifestBytes) === $entries['manifest.json']['size'], 'Size manifest non valida.');
            try {
                $manifest = json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $this->fail('Manifest JSON non valido.');
            }
            $this->assert(is_array($manifest) && ($manifest['bundle_version'] ?? null) === self::VERSION && ($manifest['data_format_version'] ?? null) === 3, 'Versione bundle o dati non supportata.');
            $this->assert(is_array($manifest['included'] ?? null) && is_bool($manifest['included']['logo'] ?? null) && is_bool($manifest['included']['files'] ?? null), 'Opzioni bundle non valide.');
            $this->assert(is_array($manifest['files'] ?? null) && array_is_list($manifest['files']), 'Inventario binari non valido.');
            $expected = ['manifest.json', 'data.xlsx'];
            $logical = [];
            foreach ($manifest['files'] as $file) {
                $this->assert(is_array($file) && in_array($file['kind'] ?? null, ['logo', 'attachment', 'budget_evidence'], true) && is_string($file['ref'] ?? null), 'Riferimento binario non valido.');
                $this->assert(array_key_exists('media_type', $file) && ($file['media_type'] === null || is_string($file['media_type'])), 'Media type binario non valido.');
                $folder = match ($file['kind']) {
                    'logo' => 'logo', 'attachment' => 'attachments', 'budget_evidence' => 'budget-evidence',
                    default => throw new \UnexpectedValueException('Unknown file kind.'),
                };
                $this->assert(($file['path'] ?? null) === 'files/'.$folder.'/'.$file['ref'], 'Path binario non valido.');
                $this->assert(! isset($logical[$file['kind'].':'.$file['ref']]), 'Riferimento binario duplicato.');
                $logical[$file['kind'].':'.$file['ref']] = $file;
                $this->assert($manifest['included'][$file['kind'] === 'logo' ? 'logo' : 'files'], 'Binario presente in una sezione esclusa.');
                $expected[] = $file['path'];
                $this->assertDescriptor($file, $entries);
            }
            $this->assert(is_array($manifest['data'] ?? null) && ($manifest['data']['path'] ?? null) === 'data.xlsx', 'Descrittore dati non valido.');
            $this->assertDescriptor($manifest['data'], $entries);
            $actual = array_keys($entries);
            sort($actual);
            sort($expected);
            $this->assert($actual === $expected, 'Il ZIP contiene entry mancanti o inattese.');
            $workbook = $this->temporaryFile();
            $this->readEntry($zip, $manifest['data'], $workbook);
            foreach ($manifest['files'] as $file) {
                $this->readEntry($zip, $file);
            }
            $inner = new ZipArchive;
            $this->assert($inner->open($workbook) === true, 'data.xlsx non è un workbook leggibile.');
            try {
                $innerEntries = $this->inspectEntries($inner);
                $this->limit(array_sum(array_column($innerEntries, 'size')), 'max_workbook_bytes');
            } finally {
                $inner->close();
            }
            $package = app(BusinessBackupValidator::class)->validate($workbook, true);
            foreach (['package_id', 'source_tenant_uuid', 'company_name', 'company_timezone'] as $key) {
                $this->assert(($manifest[$key] ?? null) === $package['manifest'][$key], "Manifest incoerenti per [$key].");
            }
            $this->assert($package['manifest']['format_version'] === '3', 'Il bundle richiede dati V3.');
            $this->assertFileInventory($package, $logical, $manifest['included']['files']);
            $package['bundle'] = $manifest;
            $package['bundle_path'] = $path;
            $package['upload_sha256'] = hash_file('sha256', $path);
            $package['package_size'] = filesize($path);
            $package['declared_total_bytes'] = array_sum(array_column($entries, 'size'));

            return $package;
        } finally {
            $zip->close();
            if ($workbook !== null) {
                @unlink($workbook);
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function inspectEntries(ZipArchive $zip): array
    {
        $this->limit($zip->numFiles, 'max_entries');
        $entries = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $this->assert(is_array($stat), 'Directory ZIP non leggibile.');
            $name = $stat['name'];
            $this->assert($name !== '' && ! str_starts_with($name, '/') && ! str_contains($name, '\\') && ! str_contains($name, ':') && ! in_array('..', explode('/', $name), true), 'Path ZIP non sicuro.');
            $this->assert(! isset($entries[$name]), 'Entry ZIP duplicata.');
            $this->assert(($stat['encryption_method'] ?? 0) === ZipArchive::EM_NONE, 'Entry ZIP cifrata non ammessa.');
            $zip->getExternalAttributesIndex($i, $os, $attributes);
            $type = ($attributes >> 16) & 0170000;
            $this->assert($os !== ZipArchive::OPSYS_UNIX || in_array($type, [0, 0100000, 0040000], true), 'Entry ZIP speciale o symlink non ammessa.');
            $this->assert($type !== 0040000 || (str_ends_with($name, '/') && $stat['size'] === 0), 'Directory ZIP non valida.');
            $this->limit($stat['size'], 'max_entry_bytes');
            $total += $stat['size'];
            $this->limit($total, 'max_total_bytes');
            $entries[$name] = $stat;
        }

        return $entries;
    }

    /** @param array<string, mixed> $descriptor
     * @param  array<string, array<string, mixed>>  $entries
     */
    private function assertDescriptor(array $descriptor, array $entries): void
    {
        $this->assert(is_string($descriptor['path'] ?? null) && isset($entries[$descriptor['path']]) && is_int($descriptor['size'] ?? null) && $descriptor['size'] >= 0 && $descriptor['size'] === $entries[$descriptor['path']]['size'], 'Size binario incoerente.');
        $this->assert(is_string($descriptor['sha256'] ?? null) && (bool) preg_match('/^[a-f0-9]{64}$/D', $descriptor['sha256']), 'SHA-256 binario non valido.');
    }

    /** @param array<string, mixed> $file */
    public function readEntry(ZipArchive $zip, array $file, ?string $destination = null): void
    {
        $stream = $zip->getStream($file['path']);
        $this->assert(is_resource($stream), 'Binario ZIP non leggibile.');
        $output = $destination === null ? null : fopen($destination, 'wb');
        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (! feof($stream)) {
                $bytes = fread($stream, 1048576);
                $this->assert(is_string($bytes) && ($bytes !== '' || feof($stream)), 'Lettura binario ZIP non riuscita.');
                $size += strlen($bytes);
                $this->limit($size, 'max_entry_bytes');
                $this->assert($size <= $file['size'], 'Il binario supera la size dichiarata.');
                hash_update($hash, $bytes);
                if ($output !== null) {
                    $this->assert(fwrite($output, $bytes) === strlen($bytes), 'Scrittura file temporaneo non riuscita.');
                }
            }
            $this->assert($size === $file['size'] && hash_equals($file['sha256'], hash_final($hash)), 'Size o SHA-256 del binario non coincidono.');
        } finally {
            fclose($stream);
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    /** @param array<string, mixed> $package
     * @param  array<string, array<string, mixed>>  $logical
     */
    private function assertFileInventory(array $package, array $logical, bool $included): void
    {
        $expected = [];
        foreach (['_MP2_attachments' => 'attachment', '_MP2_budget_evidence' => 'budget_evidence'] as $sheet => $kind) {
            foreach ($package['machine'][$sheet]['rows'] as $values) {
                $row = array_combine($package['machine'][$sheet]['columns'], $values);
                if ($kind === 'budget_evidence') {
                    $this->assert(in_array($row['has_original_file'], ['0', '1'], true), 'Disponibilità file Evidence non valida.');
                    $this->assert($row['attachment_ref'] === '' || $row['has_original_file'] === '1', 'Evidence collegata senza file originale.');
                    if ($row['attachment_ref'] !== '' || $row['has_original_file'] === '0') {
                        continue;
                    }
                }
                $ref = $values[0];
                if ($included) {
                    $this->assert(isset($logical[$kind.':'.$ref]), 'Binario inventariato mancante.');
                    $file = $logical[$kind.':'.$ref];
                    $this->assert((string) $file['size'] === $row['size'] && $file['sha256'] === $row['sha256'] && ($file['media_type'] ?? '') === $row['media_type'], 'Binario incoerente con l’inventario XLSX.');
                    $expected[] = $kind.':'.$ref;
                }
            }
        }
        foreach ($logical as $key => $file) {
            $this->assert(($file['kind'] === 'logo' && $file['ref'] === 'COM-0000000001') || in_array($key, $expected, true), 'Binario senza riferimento nei dati.');
        }
    }

    public function temporaryFile(): string
    {
        $directory = storage_path('app/private/business-backups');
        File::ensureDirectoryExists($directory);
        $path = tempnam($directory, 'mp2-');
        if ($path === false) {
            throw new \RuntimeException('Impossibile creare il file temporaneo del backup.');
        }

        return $path;
    }

    private function limit(int $value, string $key): void
    {
        $this->assert($value <= config('business_backup.'.$key), "Limite tecnico dell’istanza superato [$key].");
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            $this->fail($message);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['backup' => $message]);
    }
}
