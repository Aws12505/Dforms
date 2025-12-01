<?php

namespace App\Services;

use App\Models\FieldType;
use Illuminate\Support\Facades\DB;

class FieldTypeService
{
    /**
     * Get all field types
     */
    public function getAllFieldTypes()
    {
        return FieldType::orderBy('name', 'asc')->get();
    }

    /**
     * Get all field types with their compatible input rules
     */
    public function getAllFieldTypesWithRules()
    {
        return FieldType::with(['inputRules' => function($query) {
            $query->where('is_public', true)->orderBy('name', 'asc');
        }])
        ->orderBy('name', 'asc')
        ->get()
        ->map(function($fieldType) {
            return [
                'id' => $fieldType->id,
                'name' => $fieldType->name,
                'compatible_rules' => $fieldType->inputRules->map(function($rule) {
                    return [
                        'id' => $rule->id,
                        'name' => $rule->name,
                        'description' => $rule->description,
                    ];
                }),
            ];
        });
    }

    /**
     * Create a new field type
     */
    public function createFieldType(array $data)
    {
        DB::beginTransaction();
        try {
            $fieldType = FieldType::create($data);

            DB::commit();
            return $fieldType;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get a specific field type by ID
     */
    public function getFieldTypeById(int $id)
    {
        return FieldType::with('inputRules')->findOrFail($id);
    }

    /**
     * Update an existing field type
     */
    public function updateFieldType(int $id, array $data)
    {
        $fieldType = FieldType::findOrFail($id);
        $fieldType->update($data);
        return $fieldType->load('inputRules');
    }

    /**
     * Delete a field type
     */
    public function deleteFieldType(int $id)
    {
        DB::beginTransaction();
        try {
            $fieldType = FieldType::findOrFail($id);

            // Check if field type is being used by any fields
            $usageCount = $fieldType->fields()->count();

            if ($usageCount > 0) {
                throw new \Exception("Cannot delete this field type. It is currently used by {$usageCount} fields in forms.");
            }

            // Detach all input rule associations
            $fieldType->inputRules()->detach();

            // Delete associated filters
            $fieldType->filters()->delete();

            $fieldType->delete();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
