<?php

use App\Http\Controllers\Api\V1\IssueCategoryController;
use App\Http\Controllers\Api\V1\IssueController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\VersionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:api,api-key');

Route::middleware('auth:api,api-key')->group(function () {
    Route::get('/projects', [ProjectController::class, 'index'])->name('api.projects.index');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('api.projects.show');

    Route::get('/projects/{project}/issues', [IssueController::class, 'index'])->name('api.issues.index');
    Route::post('/projects/{project}/issues', [IssueController::class, 'store'])->name('api.issues.store');
    Route::get('/issues/{issue}', [IssueController::class, 'show'])->name('api.issues.show');
    Route::put('/issues/{issue}', [IssueController::class, 'update'])->name('api.issues.update');
    Route::delete('/issues/{issue}', [IssueController::class, 'destroy'])->name('api.issues.destroy');

    Route::get('/projects/{project}/versions', [VersionController::class, 'index'])->name('api.versions.index');
    Route::post('/projects/{project}/versions', [VersionController::class, 'store'])->name('api.versions.store');
    Route::get('/versions/{version}', [VersionController::class, 'show'])->name('api.versions.show');
    Route::put('/versions/{version}', [VersionController::class, 'update'])->name('api.versions.update');
    Route::delete('/versions/{version}', [VersionController::class, 'destroy'])->name('api.versions.destroy');

    Route::get('/projects/{project}/issue_categories', [IssueCategoryController::class, 'index'])->name('api.issue_categories.index');
    Route::post('/projects/{project}/issue_categories', [IssueCategoryController::class, 'store'])->name('api.issue_categories.store');
    Route::get('/issue_categories/{issue_category}', [IssueCategoryController::class, 'show'])->name('api.issue_categories.show');
    Route::put('/issue_categories/{issue_category}', [IssueCategoryController::class, 'update'])->name('api.issue_categories.update');
    Route::delete('/issue_categories/{issue_category}', [IssueCategoryController::class, 'destroy'])->name('api.issue_categories.destroy');

    Route::post('/uploads', [UploadController::class, 'store'])->name('api.uploads.store');
});
