<?php

namespace App\Services;

use App\Models\Entry;
use Illuminate\Support\Facades\DB;

class EntryService
{
    protected EntryFilterService $filterService;
    
    public function __construct(EntryFilterService $filterService)
    {
        $this->filterService = $filterService;
    }
    
    /**
     * Get paginated and filtered entries list for a form version
     */
    public function getEntriesList(array $filters)
    {
        $query = Entry::with(['currentStage', 'createdByUser', 'entryValues.field'])
            ->where('form_version_id', $filters['form_version_id']);
        
        // Filter by date range (submission or latest update, whichever is latest)
        if (!empty($filters['date_from'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereDate('created_at', '>=', $filters['date_from'])
                  ->orWhereDate('updated_at', '>=', $filters['date_from']);
            });
        }
        
        if (!empty($filters['date_to'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereDate('created_at', '<=', $filters['date_to'])
                  ->orWhereDate('updated_at', '<=', $filters['date_to']);
            });
        }
        
        // Apply field-type-based filters
        if (!empty($filters['field_filters'])) {
            $query = $this->filterService->applyFieldFilters($query, $filters['field_filters']);
        }
        
        // Always sort by latest (updated_at desc)
        $query->orderBy('updated_at', 'desc');
        
        $perPage = $filters['per_page'] ?? 15;
        
        return $query->paginate($perPage);
    }

    /**
     * Get entry by ID with full form structure and values
     */
    public function getEntryById(int $id)
    {
        return Entry::with([
            'formVersion.stages.sections.fields',
            'currentStage',
            'entryValues.field',
            'createdByUser'
        ])->findOrFail($id);
    }

    /**
     * Get entry by public identifier
     */
    public function getEntryByPublicIdentifier(string $publicIdentifier)
    {
        return Entry::with([
            'formVersion.stages.sections.fields',
            'currentStage',
            'entryValues.field',
            'createdByUser'
        ])->where('public_identifier', $publicIdentifier)->firstOrFail();
    }

    /**
     * Toggle considered status for single or bulk entries
     */
    public function toggleConsidered(array $entryIds, bool $isConsidered)
    {
        DB::beginTransaction();

        try {
            Entry::whereIn('id', $entryIds)->update([
                'is_considered' => $isConsidered,
            ]);

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
