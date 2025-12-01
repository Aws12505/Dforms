<?php

namespace App\Services;

use App\Models\User;
use App\Models\Language;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
class UserLanguageService
{
    /**
     * Set user's default language
     */
    public function setUserDefaultLanguage(int $userId, int $languageId)
    {
        DB::beginTransaction();

        try {
            $language = Language::findOrFail($languageId);
            $user = User::findOrFail($userId);

            $user->update([
                'default_language_id' => $languageId,
            ]);

            DB::commit();

            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get user's default language
     */
    public function getUserDefaultLanguage(int $userId)
    {
        $user = User::with('defaultLanguage')->findOrFail($userId);

        if (!$user->default_language_id) {
            // Return system default language if user has none set
            return Language::where('is_default', true)->first();
        }

        return $user->defaultLanguage;
    }

    /**
     * Get all available languages for selection
     */
    public function getAllLanguages()
{
    $user = Auth::user();
    $defaultId = $user?->default_language_id; // null if not logged in

    return Language::all()->map(function (Language $language) use ($defaultId) {
        $language->is_user_default = ($defaultId !== null && $language->id == $defaultId);
        return $language;
    });
}
}
