<?php

use App\Http\Controllers\FormBuilderDataController;
use Illuminate\Support\Facades\Route;

// Get all form builder data at once
Route::get('/all', [FormBuilderDataController::class, 'getFormBuilderData']);

// Get specific data endpoints
Route::get('/field-types', [FormBuilderDataController::class, 'getFieldTypes']);
Route::get('/actions', [FormBuilderDataController::class, 'getActions']);
Route::get('/access-data', [FormBuilderDataController::class, 'getAccessData']);
