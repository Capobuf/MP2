<?php

use App\Support\Reporting\WeasyPrintRuntime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

it('caches a discovered WeasyPrint path and rediscovers it when it disappears', function (): void {
    $root = sys_get_temp_dir().'/mp2-weasyprint-'.bin2hex(random_bytes(6));
    $firstHome = $root.'/first';
    $secondHome = $root.'/second';
    $emptyPath = $root.'/empty-bin';
    $firstBinary = $firstHome.'/.local/bin/weasyprint';
    $secondBinary = $secondHome.'/.local/bin/weasyprint';
    $originalHome = getenv('HOME');
    $originalPath = getenv('PATH');
    $originalPipxBin = getenv('PIPX_BIN_DIR');
    $originalPipxGlobalBin = getenv('PIPX_GLOBAL_BIN_DIR');

    File::ensureDirectoryExists(dirname($firstBinary));
    File::ensureDirectoryExists(dirname($secondBinary));
    File::ensureDirectoryExists($emptyPath);
    file_put_contents($firstBinary, "#!/bin/sh\necho 'WeasyPrint version 68.1'\n");
    file_put_contents($secondBinary, "#!/bin/sh\necho 'WeasyPrint version 70.0'\n");
    chmod($firstBinary, 0755);
    chmod($secondBinary, 0755);

    config(['reporting.weasyprint_binary' => 'missing-weasyprint-command']);
    Cache::store('file')->forget('reporting.weasyprint_runtime');
    putenv('PATH='.$emptyPath);
    putenv('PIPX_BIN_DIR');
    putenv('PIPX_GLOBAL_BIN_DIR');

    try {
        putenv('HOME='.$firstHome);
        $first = app(WeasyPrintRuntime::class)->status();

        putenv('HOME='.$secondHome);
        $cached = app(WeasyPrintRuntime::class)->status();

        unlink($firstBinary);
        $rediscovered = app(WeasyPrintRuntime::class)->status();

        expect($first)->toMatchArray([
            'available' => true,
            'binary' => $firstBinary,
            'version' => '68.1',
        ])->and($cached['binary'])->toBe($firstBinary)
            ->and($rediscovered)->toMatchArray([
                'available' => true,
                'binary' => $secondBinary,
                'version' => '70.0',
            ]);
    } finally {
        Cache::store('file')->forget('reporting.weasyprint_runtime');
        $originalHome === false ? putenv('HOME') : putenv('HOME='.$originalHome);
        $originalPath === false ? putenv('PATH') : putenv('PATH='.$originalPath);
        $originalPipxBin === false ? putenv('PIPX_BIN_DIR') : putenv('PIPX_BIN_DIR='.$originalPipxBin);
        $originalPipxGlobalBin === false ? putenv('PIPX_GLOBAL_BIN_DIR') : putenv('PIPX_GLOBAL_BIN_DIR='.$originalPipxGlobalBin);
        File::deleteDirectory($root);
    }
});
