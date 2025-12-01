<?php
use App\Http\Controllers\EndUserFormController;
use Illuminate\Support\Facades\Route;

Route::get('/forms', [EndUserFormController::class, 'getAvailableForms']);
Route::get('/forms/structure', [EndUserFormController::class, 'getFormStructure']);
Route::post('/forms/submit-initial', [EndUserFormController::class, 'submitInitialStage']);
Route::get('/entries/{public_identifier}', [EndUserFormController::class, 'getEntryByPublicIdentifier']);
Route::post('/entries/submit-later-stage', [EndUserFormController::class, 'submitLaterStage']);