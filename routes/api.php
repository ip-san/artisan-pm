<?php

use App\Http\Controllers\Api\V1\IssueController;
use App\Http\Controllers\Api\V1\ProjectController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:api');

Route::middleware('auth:api')->group(function () {
    Route::get('/projects', [ProjectController::class, 'index'])->name('api.projects.index');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('api.projects.show');

    Route::get('/projects/{project}/issues', [IssueController::class, 'index'])->name('api.issues.index');
    Route::post('/projects/{project}/issues', [IssueController::class, 'store'])->name('api.issues.store');
    Route::get('/issues/{issue}', [IssueController::class, 'show'])->name('api.issues.show');
    Route::put('/issues/{issue}', [IssueController::class, 'update'])->name('api.issues.update');
    Route::delete('/issues/{issue}', [IssueController::class, 'destroy'])->name('api.issues.destroy');
});
