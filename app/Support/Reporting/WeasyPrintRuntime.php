<?php

namespace App\Support\Reporting;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

final class WeasyPrintRuntime
{
    /** @return array{available: bool, reason: string|null, version: string|null, message: string} */
    public function status(): array
    {
        $binary = (string) config('reporting.weasyprint_binary');
        $supportedVersion = (string) config('reporting.weasyprint_version');

        if ($binary === '') {
            return $this->failure('missing', 'Il comando WeasyPrint non è configurato.');
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR) && ! file_exists($binary)) {
            return $this->failure('missing', 'Il binario WeasyPrint configurato non esiste.');
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR) && ! is_executable($binary)) {
            return $this->failure('not_executable', 'Il binario WeasyPrint configurato non è eseguibile.');
        }

        try {
            $result = Process::timeout(5)->run([$binary, '--version']);
        } catch (ProcessTimedOutException $exception) {
            return $this->failure('timeout', 'La verifica di WeasyPrint ha superato il tempo massimo.', $exception);
        } catch (\Throwable $exception) {
            return $this->failure('missing', 'WeasyPrint non è disponibile nel PATH del processo PHP.', $exception);
        }

        if (! $result->successful()) {
            $output = trim($result->errorOutput().' '.$result->output());
            $reason = str_contains(strtolower($output), 'permission denied') ? 'not_executable' : 'missing';

            return $this->failure($reason, 'WeasyPrint non può essere eseguito: '.$output);
        }

        $output = trim($result->output().' '.$result->errorOutput());
        preg_match('/(?:version\s+)?(\d+\.\d+(?:\.\d+)?)/i', $output, $matches);
        $version = $matches[1] ?? null;

        if ($version !== $supportedVersion) {
            return [
                'available' => false,
                'reason' => 'unsupported_version',
                'version' => $version,
                'message' => sprintf('Versione WeasyPrint non supportata: attesa %s, rilevata %s.', $supportedVersion, $version ?? 'sconosciuta'),
            ];
        }

        return ['available' => true, 'reason' => null, 'version' => $version, 'message' => 'WeasyPrint '.$version.' disponibile.'];
    }

    /** @return array{available: false, reason: string, version: null, message: string} */
    private function failure(string $reason, string $message, ?\Throwable $exception = null): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'version' => null,
            'message' => $exception === null ? $message : $message.' '.$exception->getMessage(),
        ];
    }
}
