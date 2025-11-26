<?php

namespace App\Services;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Stage;
use App\Models\Section;
use App\Models\Field;
use Illuminate\Support\Facades\DB;

class FormVersionService
{
    /**
     * Create a new version - either blank or copied from current
     */
    public function createNewVersion(int $formId, bool $copyFromCurrent)
    {
        DB::beginTransaction();

        try {
            $form = Form::findOrFail($formId);

            // Get current/latest version
            $currentVersion = FormVersion::where('form_id', $formId)
                ->orderBy('version_number', 'desc')
                ->first();

            if (!$currentVersion) {
                throw new \Exception('No current version found to create new version from.');
            }

            // Calculate new version number
            $newVersionNumber = $currentVersion->version_number + 1;

            // Create new version
            $newVersion = FormVersion::create([
                'form_id' => $formId,
                'version_number' => $newVersionNumber,
                'status' => 'draft',
                'published_at' => null,
            ]);

            if ($copyFromCurrent) {
                // COPY from current version: Clone all stages, sections, and fields
                $stages = Stage::where('form_version_id', $currentVersion->id)
                    ->with(['sections.fields'])
                    ->get();

                foreach ($stages as $stage) {
                    $newStage = Stage::create([
                        'form_version_id' => $newVersion->id,
                        'name' => $stage->name,
                        'is_initial' => $stage->is_initial,
                    ]);

                    foreach ($stage->sections as $section) {
                        $newSection = Section::create([
                            'stage_id' => $newStage->id,
                            'name' => $section->name,
                            'order' => $section->order,
                            'visibility_conditions' => $section->visibility_conditions,
                        ]);

                        foreach ($section->fields as $field) {
                            Field::create([
                                'section_id' => $newSection->id,
                                'field_type_id' => $field->field_type_id,
                                'label' => $field->label,
                                'helper_text' => $field->helper_text,
                                'placeholder' => $field->placeholder,
                                'default_value' => $field->default_value,
                                'visibility_conditions' => $field->visibility_conditions,
                            ]);
                        }
                    }
                }
            } else {
                // BLANK version: Create initial stage with one section only
                $initialStage = Stage::create([
                    'form_version_id' => $newVersion->id,
                    'name' => 'initial stage',
                    'is_initial' => true,
                ]);

                Section::create([
                    'stage_id' => $initialStage->id,
                    'name' => 'Section 1',
                    'order' => 0,
                    'visibility_conditions' => null,
                ]);
            }

            DB::commit();

            return $newVersion->load(['stages.sections.fields']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update a draft form version
     */
    public function updateFormVersion(int $id, array $data)
    {
        DB::beginTransaction();

        try {
            $formVersion = FormVersion::findOrFail($id);

            if ($formVersion->status !== 'draft') {
                throw new \Exception('Only draft versions can be updated.');
            }

            // Delete existing stages, sections, fields
            Stage::where('form_version_id', $id)->delete();

            // Create new structure from data
            foreach ($data['stages'] as $stageData) {
                $stage = Stage::create([
                    'form_version_id' => $formVersion->id,
                    'name' => $stageData['name'],
                    'is_initial' => $stageData['is_initial'],
                    'order' => $stageData['order'],
                ]);

                foreach ($stageData['sections'] as $sectionData) {
                    $section = Section::create([
                        'stage_id' => $stage->id,
                        'name' => $sectionData['name'],
                        'order' => $sectionData['order'],
                        'visibility_conditions' => $sectionData['visibility_conditions'] ?? null,
                    ]);

                    foreach ($sectionData['fields'] as $fieldData) {
                        Field::create([
                            'section_id' => $section->id,
                            'field_type_id' => $fieldData['field_type_id'],
                            'label' => $fieldData['label'],
                            'helper_text' => $fieldData['helper_text'] ?? null,
                            'placeholder' => $fieldData['placeholder'] ?? null,
                            'default_value' => $fieldData['default_value'] ?? null,
                            'visibility_conditions' => $fieldData['visibility_conditions'] ?? null,
                        ]);
                    }
                }
            }

            DB::commit();

            return $formVersion->load(['stages.sections.fields']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Publish a draft form version
     */
    public function publishFormVersion(int $id)
    {
        DB::beginTransaction();

        try {
            $formVersion = FormVersion::findOrFail($id);

            if ($formVersion->status !== 'draft') {
                throw new \Exception('Only draft versions can be published.');
            }

            // Set all other versions of this form as non-published
            FormVersion::where('form_id', $formVersion->form_id)
                ->where('id', '!=', $id)
                ->update(['status' => 'draft']);

            // Publish this version
            $formVersion->update([
                'status' => 'published',
                'published_at' => now(),
            ]);

            DB::commit();

            return $formVersion;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get form version by ID with full structure
     */
    public function getFormVersionById(int $id)
    {
        return FormVersion::with(['stages.sections.fields', 'form'])->findOrFail($id);
    }

    /**
     * Get all versions of a form
     */
    public function getFormVersions(int $formId)
    {
        return FormVersion::where('form_id', $formId)
            ->orderBy('version_number', 'desc')
            ->get();
    }
}
