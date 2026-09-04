<?php

namespace App\Support\Reporting;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

final class WeasyPrintRuntime
{
    private const CACHE_KEY = 'reporting.weasyprint_runtime';

    /** @return array{available: bool, reason: string|null, binary: string|null, version: string|null, message: string} */
    public function status(): array
    {
        $configured = trim((string) config('reporting.weasyprint_binary'));
        $cached = Cache::store('file')->get(self::CACHE_KEY);

        if (is_array($cached)
            && ($cached['configured'] ?? null) === $configured
            && is_string($cached['binary'] ?? null)
            && $cached['binary'] !== '') {
            $status = $this->check((string) $cached['binary']);

            if ($status['available']) {
                return $status;
            }

            Cache::store('file')->forget(self::CACHE_KEY);
        }

        $reasons = [];

        foreach ($this->candidates($configured) as $binary) {
            $status = $this->check($binary);

            if ($status['available']) {
                Cache::store('file')->forever(self::CACHE_KEY, [
                    'configured' => $configured,
                    'binary' => $binary,
                ]);

                return $status;
            }

            $reasons[] = $status['reason'];
        }

        $reason = in_array('timeout', $reasons, true)
            ? 'timeout'
            : (in_array('not_executable', $reasons, true) ? 'not_executable' : 'missing');

        return $this->failure(
            $reason,
            'WeasyPrint non è stato trovato o non è eseguibile nei percorsi disponibili.',
        );
    }

    /** @return list<string> */
    private function candidates(string $configured): array
    {
        $candidates = $configured === '' ? [] : [$configured];
        $directories = [];
        $path = getenv('PATH');

        if (is_string($path) && $path !== '') {
            $directories = array_merge($directories, explode(PATH_SEPARATOR, $path));
        }

        $home = $this->homeDirectory();
        $directories = array_merge($directories, array_filter([
            getenv('PIPX_BIN_DIR') ?: null,
            getenv('PIPX_GLOBAL_BIN_DIR') ?: null,
            $home === null ? null : $home.DIRECTORY_SEPARATOR.'.local'.DIRECTORY_SEPARATOR.'bin',
            $home === null ? null : $home.DIRECTORY_SEPARATOR.'bin',
            base_path('.venv/bin'),
            base_path('venv/bin'),
            '/usr/local/bin',
            '/usr/bin',
            '/opt/weasyprint/bin',
            '/snap/bin',
        ], fn (mixed $directory): bool => is_string($directory) && $directory !== ''));

        foreach (array_unique($directories) as $directory) {
            $candidates[] = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'weasyprint';
        }

        return array_values(array_unique($candidates));
    }

    private function homeDirectory(): ?string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, DIRECTORY_SEPARATOR);
        }

        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());
            if (is_array($user) && $user['dir'] !== '') {
                return rtrim($user['dir'], DIRECTORY_SEPARATOR);
            }
        }

        return null;
    }

    /** @return array{available: bool, reason: string|null, binary: string|null, version: string|null, message: string} */
    private function check(string $binary): array
    {
        if (str_contains($binary, DIRECTORY_SEPARATOR) && ! file_exists($binary)) {
            return $this->failure('missing', 'Il binario WeasyPrint non esiste.');
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR) && ! is_executable($binary)) {
            return $this->failure('not_executable', 'Il binario WeasyPrint non è eseguibile.');
        }

        try {
            $result = Process::timeout(5)->run([$binary, '--version']);
        } catch (ProcessTimedOutException $exception) {
            return $this->failure('timeout', 'La verifica di WeasyPrint ha superato il tempo massimo.', $exception);
        } catch (\Throwable $exception) {
            return $this->failure('missing', 'WeasyPrint non può essere avviato.', $exception);
        }

        if (! $result->successful()) {
            $output = trim($result->errorOutput().' '.$result->output());
            $reason = str_contains(strtolower($output), 'permission denied') ? 'not_executable' : 'missing';

            return $this->failure($reason, 'WeasyPrint non può essere eseguito: '.$output);
        }

        $output = trim($result->output().' '.$result->errorOutput());
        preg_match('/(?:version\s+)?(\d+\.\d+(?:\.\d+)?)/i', $output, $matches);
        $version = $matches[1] ?? null;

        return [
            'available' => true,
            'reason' => null,
            'binary' => $binary,
            'version' => $version,
            'message' => 'WeasyPrint'.($version === null ? '' : ' '.$version).' disponibile in '.$binary.'.',
        ];
    }

    /** @return array{available: false, reason: string, binary: null, version: null, message: string} */
    private function failure(string $reason, string $message, ?\Throwable $exception = null): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'binary' => null,
            'version' => null,
            'message' => $exception === null ? $message : $message.' '.$exception->getMessage(),
        ];
    }
}
