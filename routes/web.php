<?php

use App\Http\Controllers\AttachmentDownloadController;
use App\Http\Controllers\BudgetEvidenceDownloadController;
use App\Http\Controllers\ReportPdfController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::get('/attachments/{attachment}/download', AttachmentDownloadController::class)
    ->middleware('auth')
    ->name('attachments.download');

Route::get('/budget-evidence/{evidence}/download', BudgetEvidenceDownloadController::class)
    ->middleware('auth')
    ->name('budget-evidence.download');

Route::get('/reports/pdf', ReportPdfController::class)
    ->middleware('auth')
    ->name('reports.pdf');
