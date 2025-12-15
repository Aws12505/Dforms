<?php

namespace App\Services;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Stage;
use App\Models\Section;
use Illuminate\Support\Facades\DB;

class FormService
{
    /**
     * Get paginated and filtered forms list
     */
    public function getFormsList(array $filters)
    {
        $query = Form::with(['category']);

        // Filter by name
        if (!empty($filters['name'])) {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
        }

        // Filter by date range
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        // Filter by status
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            switch ($filters['status']) {
                case 'drafted':
                    $query->whereHas('formVersions', function ($q) {
                        $q->where('status', 'draft');
                    });
                    break;
                case 'published':
                    $query->where('is_archived', false)
                          ->whereHas('formVersions', function ($q) {
                              $q->where('status', 'published');
                          });
                    break;
                case 'archived':
                    $query->where('is_archived', true);
                    break;
            }
        }

        // Filter by category
        if (!empty($filters['category_filter_type'])) {
            switch ($filters['category_filter_type']) {
                case 'specific':
                    if (!empty($filters['category_ids']) && count($filters['category_ids']) === 1) {
                        $query->where('category_id', $filters['category_ids'][0]);
                    }
                    break;
                case 'group':
                    if (!empty($filters['category_ids'])) {
                        $query->whereIn('category_id', $filters['category_ids']);
                    }
                    break;
                case 'without':
                    $query->whereNull('category_id');
                    break;
            }
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'creation_time';
        $sortDirection = $filters['sort_direction'] ?? 'desc';

        switch ($sortBy) {
            case 'latest_submission':
               $query->withMax('entries', 'updated_at')
                     ->orderBy('entries_max_updated_at', $sortDirection);
               break;
            case 'publish_time':
                $query->withMax('formVersions as latest_publish_time', 'published_at')
                      ->orderBy('latest_publish_time', $sortDirection);
                break;
            case 'creation_time':
            default:
                $query->orderBy('created_at', $sortDirection);
                break;
        }

        $perPage = $filters['per_page'] ?? 15;
        
        return $query->paginate($perPage);
    }

    /**
     * Create a new form WITH auto-created version 0, initial stage, and first section
     */
    public function createForm(array $data)
    {
        DB::beginTransaction();

        try {
            // 1. Create the form
            $form = Form::create([
                'name' => $data['name'],
                'category_id' => $data['category_id'] ?? null,
                'is_archived' => false,
            ]);

            // 2. Auto-create FormVersion 0 as draft
            $formVersion = FormVersion::create([
                'form_id' => $form->id,
                'version_number' => 0,
                'status' => 'draft',
                'published_at' => null,
            ]);

            // 3. Auto-create "initial stage"
            $initialStage = Stage::create([
                'form_version_id' => $formVersion->id,
                'name' => 'initial stage',
                'is_initial' => true,
            ]);

            // 4. Auto-create first section in initial stage
            Section::create([
                'stage_id' => $initialStage->id,
                'name' => 'Section 1',
                'order' => 0,
                'visibility_conditions' => null,
            ]);

            DB::commit();

            return $form->load(['category', 'formVersions.stages.sections']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get a specific form by ID
     */
    public function getFormById(int $id)
    {
        return Form::with(['category', 'formVersions'])->findOrFail($id);
    }

    /**
     * Update an existing form
     */
    public function updateForm(int $id, array $data)
    {
        $form = Form::findOrFail($id);
        $form->update($data);
        return $form->load(['category', 'formVersions']);
    }

    /**
     * Archive a form and all its versions
     */
    public function archiveForm(int $id)
    {
        DB::beginTransaction();

        try {
            $form = Form::findOrFail($id);
            $form->update(['is_archived' => true]);
            FormVersion::where('form_id', $id)->update(['status' => 'archived']);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Restore a form from archive
     */
    public function restoreForm(int $id)
    {
        DB::beginTransaction();

        try {
            $form = Form::findOrFail($id);
            $form->update(['is_archived' => false]);

            $latestVersion = FormVersion::where('form_id', $id)
                ->orderBy('version_number', 'desc')
                ->first();

            if ($latestVersion) {
                $latestVersion->update([
                    'status' => 'published',
                    'published_at' => now(),
                ]);
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
