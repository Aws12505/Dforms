<?php

namespace App\Services;

use App\Models\FormVersion;
use App\Models\Language;
use App\Models\FormVersionTranslation;
use App\Models\FieldTranslation;
use App\Models\Field;
use Illuminate\Support\Facades\DB;

class TranslationService
{
    /**
     * Get all localizable data for a form version in default language
     */
    public function getLocalizableData(int $formVersionId, int $languageId)
    {
        // Validate that the target language is NOT the default language
        $language = Language::findOrFail($languageId);
        
        if ($language->is_default) {
            throw new \Exception('Cannot create translations for the default language.');
        }

        $formVersion = FormVersion::with([
            'form',
            'stages.sections.fields'
        ])->findOrFail($formVersionId);

        // Get all fields from all stages/sections
        $fields = [];
        foreach ($formVersion->stages as $stage) {
            foreach ($stage->sections as $section) {
                foreach ($section->fields as $field) {
                    $fields[] = [
                        'field_id' => $field->id,
                        'label' => $field->label,
                        'helper_text' => $field->helper_text,
                        'default_value' => $field->default_value,
                        'place_holder' => $field->placeholder,
                    ];
                }
            }
        }

        return [
            'form_version_id' => $formVersion->id,
            'form_name' => $formVersion->form->name,
            'language' => $language,
            'fields' => $fields,
        ];
    }

    /**
     * Save translations for a form version and language
     */
    public function saveTranslations(array $data)
    {
        DB::beginTransaction();

        try {
            $formVersionId = $data['form_version_id'];
            $languageId = $data['language_id'];

            // Validate that the target language is NOT the default language
            $language = Language::findOrFail($languageId);
            
            if ($language->is_default) {
                throw new \Exception('Cannot save translations for the default language.');
            }

            // Save or update form version translation (form name)
            if (!empty($data['form_name'])) {
                FormVersionTranslation::updateOrCreate(
                    [
                        'form_version_id' => $formVersionId,
                        'language_id' => $languageId,
                    ],
                    [
                        'name' => $data['form_name'],
                    ]
                );
            }

            // Save or update field translations
            foreach ($data['field_translations'] as $fieldTranslation) {
                $fieldId = $fieldTranslation['field_id'];

                // Only save if at least one translation field is provided
                if (!empty($fieldTranslation['label']) || 
                    !empty($fieldTranslation['helper_text']) || 
                    !empty($fieldTranslation['default_value'])) {
                    
                    FieldTranslation::updateOrCreate(
                        [
                            'field_id' => $fieldId,
                            'language_id' => $languageId,
                        ],
                        [
                            'label' => $fieldTranslation['label'] ?? '',
                            'helper_text' => $fieldTranslation['helper_text'] ?? '',
                            'default_value' => $fieldTranslation['default_value'] ?? '',
                        ]
                    );
                }
            }

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get all available languages (excluding default for translation purposes)
     */
    public function getAvailableLanguagesForTranslation()
    {
        return Language::where('is_default', false)->get();
    }

    /**
     * Get default language
     */
    public function getDefaultLanguage()
    {
        return Language::where('is_default', true)->first();
    }
}
