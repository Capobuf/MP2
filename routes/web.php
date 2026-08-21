<?php

use App\Http\Controllers\AttachmentDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::get('/attachments/{attachment}/download', AttachmentDownloadController::class)
    ->middleware('auth')
    ->name('attachments.download');
