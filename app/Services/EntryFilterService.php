<?php

namespace App\Services;

use App\Models\Entry;
use App\Models\FieldTypeFilter;
use Illuminate\Database\Eloquent\Builder;

class EntryFilterService
{
    /**
     * Apply field-type-based filters to entries query
     * 
     * @param Builder $query Entry query builder
     * @param array $filters Array of ['field_id' => filter_data]
     * @return Builder
     */
    public function applyFieldFilters(Builder $query, array $filters): Builder
    {
        foreach ($filters as $fieldId => $filterData) {
            $field = \App\Models\Field::with('fieldType')->find($fieldId);
            
            if (!$field) {
                continue;
            }
            
            $fieldTypeName = $field->fieldType->name;
            $filterMethod = $this->getFilterMethod($fieldTypeName);
            
            if (method_exists($this, $filterMethod)) {
                $query = $this->$filterMethod($query, $fieldId, $filterData);
            }
        }
        
        return $query;
    }
    
    /**
     * Get filter method name for a field type
     */
    private function getFilterMethod(string $fieldTypeName): string
    {
        // Convert "Text Input" to "filterTextInput"
        $method = 'filter' . str_replace(' ', '', $fieldTypeName);
        return $method;
    }
    
    // ==================== FILTER METHODS FOR EACH FIELD TYPE ====================
    
    private function filterTextInput(Builder $query, int $fieldId, $filterData): Builder
    {
        $searchType = $filterData['type'] ?? 'contains';
        $searchValue = $filterData['value'] ?? '';
        
        return $query->whereHas('entryValues', function ($q) use ($fieldId, $searchType, $searchValue) {
            $q->where('field_id', $fieldId);
            
            match($searchType) {
                'contains' => $q->where('value', 'like', "%{$searchValue}%"),
                'equals' => $q->where('value', '=', $searchValue),
                'starts_with' => $q->where('value', 'like', "{$searchValue}%"),
                'ends_with' => $q->where('value', 'like', "%{$searchValue}"),
                'not_contains' => $q->where('value', 'not like', "%{$searchValue}%"),
                default => $q->where('value', 'like', "%{$searchValue}%")
            };
        });
    }
    
    private function filterEmailInput(Builder $query, int $fieldId, $filterData): Builder
    {
        $searchType = $filterData['type'] ?? 'contains';
        $searchValue = $filterData['value'] ?? '';
        
        return $query->whereHas('entryValues', function ($q) use ($fieldId, $searchType, $searchValue) {
            $q->where('field_id', $fieldId);
            
            if ($searchType === 'domain') {
                $q->where('value', 'like', "%@{$searchValue}");
            } elseif ($searchType === 'equals') {
                $q->where('value', '=', $searchValue);
            } else {
                $q->where('value', 'like', "%{$searchValue}%");
            }
        });
    }
    
    private function filterNumberInput(Builder $query, int $fieldId, $filterData): Builder
    {
        return $this->filterNumericRange($query, $fieldId, $filterData);
    }
    
    private function filterPhoneInput(Builder $query, int $fieldId, $filterData): Builder
    {
        $searchType = $filterData['type'] ?? 'contains';
        $searchValue = $filterData['value'] ?? '';
        
        return $query->whereHas('entryValues', function ($q) use ($fieldId, $searchType, $searchValue) {
            $q->where('field_id', $fieldId);
            
            if ($searchType === 'country_code') {
                $q->where('value', 'like', "{$searchValue}%");
            } else {
                $q->where('value', 'like', "%{$searchValue}%");
            }
        });
    }
    
    private function filterTextArea(Builder $query, int $fieldId, $filterData): Builder
    {
        $keywords = $filterData['keywords'] ?? '';
        
        return $query->whereHas('entryValues', function ($q) use ($fieldId, $keywords) {
            $q->where('field_id', $fieldId)
              ->where('value', 'like', "%{$keywords}%");
        });
    }
    
    private function filterDateInput(Builder $query, int $fieldId, $filterData): Builder
    {
        return $this->filterDateRange($query, $fieldId, $filterData);
    }
    
    private function filterTimeInput(Builder $query, int $fieldId, $filterData): Builder
    {
        return $this->filterTimeRange($query, $fieldId, $filterData);
    }
    
    private function filterDateTimeInput(Builder $query, int $fieldId, $filterData): Builder
    {
        return $this->filterDateTimeRange($query, $fieldId, $filterData);
    }
    
    private function filterCheckbox(Builder $query, int $fieldId, $filterData): Builder
    {
        $checked = $filterData['checked'] ?? null;
        
        if ($checked === null) {
            return $query;
        }
        
        return $query->whereHas('entryValues', function ($q) use ($fieldId, $checked) {
            $q->where('field_id', $fieldId);
            
            if ($checked) {
                $q->whereIn('value', ['1', 'true', 'on', 'yes']);
            } else {
                $q->whereIn('value', ['0', 'false', 'off', 'no', '']);
            }
        });
    }
    
    private function filterRadioButton(Builder $query, int $fieldId, $filterData): Builder
    {
        return $this->filterChoiceField($query, $fieldId, $filterData);
    }
    
    private function filterDropdownSelect(Builder $query, int $fieldId, $filterData): Builder
    {
        return $this->filterChoiceField($query, $fieldId, $filterData);
    }
    
    private function filterMulti_Select(Builder $query, int $fieldId, $filterData): Builder
    {
        $selectedOptions = $filterData['options'] ?? [];
        
        if (empty($selectedOptions)) {
            return $query;
        }
        
        return $query->whereHas('entryValues', function ($q) use ($fieldId, $selectedOptions) {
            $q->where('field_id', $fieldId);
            
            // Value might be JSON array of selected options
            foreach ($selectedOptions as $option) {
                $q->orWhere('value', 'like', "%\"{$option}\"%");
            }
        });
    }
    
    private function filterFileUpload(Builder $query, int $fieldId, $filterData): Builder
    {
        return $this->filterFileType($query, $fieldId, $filterData);
    }
    
    private function filterImageUpload(Builder $query, int $fieldId, $filterData): Builder
    {
        $filterType = $filterData['type'] ?? 'has_image';
        
        if ($filterType === 'has_image') {
            $hasImage = $filterData['value'] ?? true;
            
            if ($hasImage) {
                return $query->whereHas('entryValues', function ($q) use ($fieldId) {
                    $q->where('field_id', $fieldId)
                      ->whereNotNull('value')
                      ->where('value', '!=', '');
                });
            } else {
                return $query->whereDoesntHave('entryValues', function ($q) use ($fieldId) {
                    $q->where('field_id', $fieldId)
                      ->whereNotNull('value')
                      ->where('value', '!=', '');
                });
            }
        }
        
        return $this->filterFileType($query, $fieldId, $filterData);
    }
    
    private function filterVideoUpload(Builder $query, int $fieldId, $filterData): Builder
    {
        $filterType = $filterData['type'] ?? 'has_video';
        
        if ($filterType === 'has_video') {
            $hasVideo = $filterData['value'] ?? true;
            
            if ($hasVideo) {
                return $query->whereHas('entryValues', function ($q) use ($fieldId) {
                    $q->where('field_id', $fieldId)
                      ->whereNotNull('value')
                      ->where('value', '!=', '');
                });
            } else {
                return $query->whereDoesntHave('entryValues', function ($q) use ($fieldId) {
                    $q->where('field_id', $fieldId)
                      ->whereNotNull('value')
                      ->where('value', '!=', '');
                });
            }
        }
        
        return $this->filterFileType($query, $fieldId, $filterData);
    }
    
    private function filterDocumentUpload(Builder $query, int $fieldId, $filterData): Builder
    {
        return $this->filterFileType($query, $fieldId, $filterData);
    }
    
    private function filterURLInput(Builder $query, int $fieldId, $filterData): Builder
    {
        $searchType = $filterData['type'] ?? 'contains';
        $searchValue = $filterData['value'] ?? '';
        
        return $query->whereHas('entryValues', function ($q) use ($fieldId, $searchType, $searchValue) {
            $q->where('field_id', $fieldId);
            
            if ($searchType === 'domain') {
                $q->where('value', 'like', "%://{$searchValue}%");
            } else {
                $q->where('value', 'like', "%{$searchValue}%");
            }
        });
    }
    
    private function filterPasswordInput(Builder $query, int $fieldId, $filterData): Builder
    {
        // Passwords typically shouldn't be filterable, but include for completeness
        return $query;
    }
    
    private function filterColorPicker(Builder $query, int $fieldId, $filterData): Builder
    {
        $colors = $filterData['colors'] ?? [];
        
        if (empty($colors)) {
            return $query;
        }
        
        return $query->whereHas('entryValues', function ($q) use ($fieldId, $colors) {
            $q->where('field_id', $fieldId)
              ->whereIn('value', $colors);
        });
    }
    
    private function filterRating(Builder $query, int $fieldId, $filterData): Builder
    {
        return $this->filterNumericRange($query, $fieldId, $filterData);
    }
    
    private function filterSlider(Builder $query, int $fieldId, $filterData): Builder
    {
        return $this->filterNumericRange($query, $fieldId, $filterData);
    }
    
    private function filterToggleSwitch(Builder $query, int $fieldId, $filterData): Builder
    {
        $state = $filterData['state'] ?? null; // 'on' or 'off'
        
        if ($state === null) {
            return $query;
        }
        
        return $query->whereHas('entryValues', function ($q) use ($fieldId, $state) {
            $q->where('field_id', $fieldId);
            
            if ($state === 'on') {
                $q->whereIn('value', ['1', 'true', 'on', 'yes']);
            } else {
                $q->whereIn('value', ['0', 'false', 'off', 'no']);
            }
        });
    }
    
    private function filterCurrencyInput(Builder $query, int $fieldId, $filterData): Builder
    {
        return $this->filterNumericRange($query, $fieldId, $filterData);
    }
    
    private function filterPercentageInput(Builder $query, int $fieldId, $filterData): Builder
    {
        return $this->filterNumericRange($query, $fieldId, $filterData);
    }
    
    private function filterSignaturePad(Builder $query, int $fieldId, $filterData): Builder
    {
        $hasSigned = $filterData['has_signature'] ?? true;
        
        if ($hasSigned) {
            return $query->whereHas('entryValues', function ($q) use ($fieldId) {
                $q->where('field_id', $fieldId)
                  ->whereNotNull('value')
                  ->where('value', '!=', '');
            });
        } else {
            return $query->whereDoesntHave('entryValues', function ($q) use ($fieldId) {
                $q->where('field_id', $fieldId)
                  ->whereNotNull('value')
                  ->where('value', '!=', '');
            });
        }
    }
    
    private function filterLocationPicker(Builder $query, int $fieldId, $filterData): Builder
    {
        $filterType = $filterData['type'] ?? 'radius';
        
        if ($filterType === 'radius') {
            // Radius search from a center point
            $centerLat = $filterData['center_lat'] ?? 0;
            $centerLng = $filterData['center_lng'] ?? 0;
            $radiusKm = $filterData['radius'] ?? 10;
            
            return $query->whereHas('entryValues', function ($q) use ($fieldId, $centerLat, $centerLng, $radiusKm) {
                $q->where('field_id', $fieldId)
                  ->whereRaw("
                      (6371 * acos(cos(radians(?)) * cos(radians(JSON_EXTRACT(value, '$.lat'))) * 
                      cos(radians(JSON_EXTRACT(value, '$.lng')) - radians(?)) + 
                      sin(radians(?)) * sin(radians(JSON_EXTRACT(value, '$.lat'))))) <= ?
                  ", [$centerLat, $centerLng, $centerLat, $radiusKm]);
            });
        } elseif ($filterType === 'bounding_box') {
            // Bounding box search
            $minLat = $filterData['min_lat'] ?? 0;
            $maxLat = $filterData['max_lat'] ?? 0;
            $minLng = $filterData['min_lng'] ?? 0;
            $maxLng = $filterData['max_lng'] ?? 0;
            
            return $query->whereHas('entryValues', function ($q) use ($fieldId, $minLat, $maxLat, $minLng, $maxLng) {
                $q->where('field_id', $fieldId)
                  ->whereRaw("JSON_EXTRACT(value, '$.lat') BETWEEN ? AND ?", [$minLat, $maxLat])
                  ->whereRaw("JSON_EXTRACT(value, '$.lng') BETWEEN ? AND ?", [$minLng, $maxLng]);
            });
        }
        
        return $query;
    }
    
    private function filterAddressInput(Builder $query, int $fieldId, $filterData): Builder
    {
        $searchField = $filterData['field'] ?? 'any'; // city, state, country, postal_code, any
        $searchValue = $filterData['value'] ?? '';
        
        return $query->whereHas('entryValues', function ($q) use ($fieldId, $searchField, $searchValue) {
            $q->where('field_id', $fieldId);
            
            if ($searchField === 'any') {
                $q->where('value', 'like', "%{$searchValue}%");
            } else {
                // Assuming address is stored as JSON
                $q->whereRaw("JSON_EXTRACT(value, '$.{$searchField}') LIKE ?", ["%{$searchValue}%"]);
            }
        });
    }
    
    // ==================== HELPER FILTER METHODS ====================
    
    private function filterNumericRange(Builder $query, int $fieldId, $filterData): Builder
    {
        $rangeType = $filterData['type'] ?? 'equals';
        $value = $filterData['value'] ?? 0;
        $min = $filterData['min'] ?? 0;
        $max = $filterData['max'] ?? 0;
        
        return $query->whereHas('entryValues', function ($q) use ($fieldId, $rangeType, $value, $min, $max) {
            $q->where('field_id', $fieldId);
            
            match($rangeType) {
                'equals' => $q->where('value', '=', $value),
                'greater_than' => $q->where('value', '>', $value),
                'less_than' => $q->where('value', '<', $value),
                'greater_or_equal' => $q->where('value', '>=', $value),
                'less_or_equal' => $q->where('value', '<=', $value),
                'between' => $q->whereBetween('value', [$min, $max]),
                default => $q->where('value', '=', $value)
            };
        });
    }
    
    private function filterDateRange(Builder $query, int $fieldId, $filterData): Builder
    {
        $rangeType = $filterData['type'] ?? 'equals';
        $date = $filterData['date'] ?? now()->format('Y-m-d');
        $startDate = $filterData['start_date'] ?? null;
        $endDate = $filterData['end_date'] ?? null;
        
        return $query->whereHas('entryValues', function ($q) use ($fieldId, $rangeType, $date, $startDate, $endDate) {
            $q->where('field_id', $fieldId);
            
            match($rangeType) {
                'equals' => $q->whereDate('value', '=', $date),
                'before' => $q->whereDate('value', '<', $date),
                'after' => $q->whereDate('value', '>', $date),
                'before_or_equal' => $q->whereDate('value', '<=', $date),
                'after_or_equal' => $q->whereDate('value', '>=', $date),
                'between' => $q->whereBetween('value', [$startDate, $endDate]),
                default => $q->whereDate('value', '=', $date)
            };
        });
    }
    
    private function filterTimeRange(Builder $query, int $fieldId, $filterData): Builder
    {
        $rangeType = $filterData['type'] ?? 'equals';
        $time = $filterData['time'] ?? '00:00:00';
        $startTime = $filterData['start_time'] ?? null;
        $endTime = $filterData['end_time'] ?? null;
        
        return $query->whereHas('entryValues', function ($q) use ($fieldId, $rangeType, $time, $startTime, $endTime) {
            $q->where('field_id', $fieldId);
            
            match($rangeType) {
                'equals' => $q->whereTime('value', '=', $time),
                'before' => $q->whereTime('value', '<', $time),
                'after' => $q->whereTime('value', '>', $time),
                'between' => $q->whereTime('value', '>=', $startTime)->whereTime('value', '<=', $endTime),
                default => $q->whereTime('value', '=', $time)
            };
        });
    }
    
    private function filterDateTimeRange(Builder $query, int $fieldId, $filterData): Builder
    {
        $rangeType = $filterData['type'] ?? 'equals';
        $datetime = $filterData['datetime'] ?? now();
        $startDatetime = $filterData['start_datetime'] ?? null;
        $endDatetime = $filterData['end_datetime'] ?? null;
        
        return $query->whereHas('entryValues', function ($q) use ($fieldId, $rangeType, $datetime, $startDatetime, $endDatetime) {
            $q->where('field_id', $fieldId);
            
            match($rangeType) {
                'equals' => $q->where('value', '=', $datetime),
                'before' => $q->where('value', '<', $datetime),
                'after' => $q->where('value', '>', $datetime),
                'between' => $q->whereBetween('value', [$startDatetime, $endDatetime]),
                default => $q->where('value', '=', $datetime)
            };
        });
    }
    
    private function filterChoiceField(Builder $query, int $fieldId, $filterData): Builder
    {
        $selectedOptions = $filterData['options'] ?? [];
        
        if (empty($selectedOptions)) {
            return $query;
        }
        
        return $query->whereHas('entryValues', function ($q) use ($fieldId, $selectedOptions) {
            $q->where('field_id', $fieldId)
              ->whereIn('value', $selectedOptions);
        });
    }
    
    private function filterFileType(Builder $query, int $fieldId, $filterData): Builder
    {
        $fileTypes = $filterData['file_types'] ?? [];
        $hasFile = $filterData['has_file'] ?? null;
        
        if ($hasFile !== null) {
            if ($hasFile) {
                return $query->whereHas('entryValues', function ($q) use ($fieldId) {
                    $q->where('field_id', $fieldId)
                      ->whereNotNull('value')
                      ->where('value', '!=', '');
                });
            } else {
                return $query->whereDoesntHave('entryValues', function ($q) use ($fieldId) {
                    $q->where('field_id', $fieldId)
                      ->whereNotNull('value')
                      ->where('value', '!=', '');
                });
            }
        }
        
        if (!empty($fileTypes)) {
            return $query->whereHas('entryValues', function ($q) use ($fieldId, $fileTypes) {
                $q->where('field_id', $fieldId);
                
                foreach ($fileTypes as $type) {
                    $q->orWhere('value', 'like', "%.{$type}%");
                }
            });
        }
        
        return $query;
    }
}
