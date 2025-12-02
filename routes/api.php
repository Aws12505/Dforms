<?php
use Illuminate\Support\Facades\Route;

Route::middleware('auth.verify')->group(function (){

Route::prefix('languages')->group(function () {
require __DIR__.'/V1/Language.php';
});

Route::prefix('field-types')->group(function () {
require __DIR__.'/V1/FieldType.php';
});

Route::prefix('input-rules')->group(function () {
require __DIR__.'/V1/InputRule.php';
});

Route::prefix('actions')->group(function () {
require __DIR__.'/V1/Action.php';
});

Route::prefix('field-type-filters')->group(function () {
require __DIR__.'/V1/FieldTypeFilter.php';
});

Route::prefix('categories')->group(function () {
require __DIR__.'/V1/Category.php';
});

Route::prefix('forms')->group(function () {
require __DIR__.'/V1/Form.php';
});

require __DIR__.'/V1/FormVersion.php';

Route::prefix('entries')->group(function () {
require __DIR__.'/V1/Entry.php';
});

Route::prefix('translations')->group(function () {
require __DIR__.'/V1/Translation.php';
});

Route::prefix('user/language')->group(function () {
require __DIR__.'/V1/UserLanguage.php';
});

});

Route::prefix('enduser')->group(function () {
require __DIR__.'/V1/EndUser.php';
});

Route::prefix('form-builder')->group(function () {
    require __DIR__.'/V1/FormBuilderData.php';
});