<?php

namespace App\Services;

use App\Models\Field;
use App\Models\FieldRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FieldValidationService
{
    /**
     * Validate submission values against field rules
     * 
     * @param array $fieldValues Array of ['field_id' => value]
     * @param int $stageId Current stage ID for visibility checks
     * @param array $allValues All form values for conditional rules
     * @return array Validation errors if any
     */
    public function validateSubmissionValues(array $fieldValues, int $stageId, array $allValues = []): array
    {
        $errors = [];
        
        foreach ($fieldValues as $fieldId => $value) {
            $field = Field::with(['fieldType', 'rules.inputRule', 'section'])->find($fieldId);
            
            if (!$field) {
                $errors[$fieldId] = ["Field not found"];
                continue;
            }
            
            // Check if field is visible
            if (!$this->isFieldVisible($field, $allValues)) {
                continue; // Skip validation for hidden fields
            }
            
            // Validate each rule attached to this field
            foreach ($field->rules as $fieldRule) {
                if (!$this->isRuleActive($fieldRule, $allValues)) {
                    continue; // Skip inactive conditional rules
                }
                
                $ruleErrors = $this->validateSingleRule(
                    $value,
                    $field->fieldType->name,
                    $fieldRule->inputRule->name,
                    $fieldRule->rule_props ?? []
                );
                
                if (!empty($ruleErrors)) {
                    $errors[$fieldId] = array_merge($errors[$fieldId] ?? [], $ruleErrors);
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Check if field is visible based on visibility conditions
     */
    private function isFieldVisible(Field $field, array $allValues): bool
    {
        if (empty($field->visibility_condition)) {
            return true;
        }
        
        // Evaluate visibility condition
        return $this->evaluateCondition($field->visibility_condition, $allValues);
    }
    
    /**
     * Check if rule is active based on rule conditions
     */
    private function isRuleActive(FieldRule $fieldRule, array $allValues): bool
    {
        if (empty($fieldRule->rule_condition)) {
            return true;
        }
        
        return $this->evaluateCondition($fieldRule->rule_condition, $allValues);
    }
    
    /**
     * Evaluate a condition against provided values
     */
    private function evaluateCondition($condition, array $values): bool
    {
        if (empty($condition)) {
            return true;
        }
        
        // Condition format: {"field_id": 5, "operator": "equals", "value": "yes"}
        // or for complex: {"logic": "and", "conditions": [...]}
        
        if (isset($condition['logic'])) {
            // Complex condition with AND/OR logic
            $results = [];
            foreach ($condition['conditions'] as $subCondition) {
                $results[] = $this->evaluateCondition($subCondition, $values);
            }
            
            if ($condition['logic'] === 'and') {
                return !in_array(false, $results, true);
            } else { // or
                return in_array(true, $results, true);
            }
        }
        
        // Simple condition
        $fieldId = $condition['field_id'] ?? null;
        $operator = $condition['operator'] ?? 'filled';
        $expectedValue = $condition['value'] ?? null;
        
        if (!$fieldId || !isset($values[$fieldId])) {
            return false;
        }
        
        $actualValue = $values[$fieldId];
        
        return match($operator) {
            'filled' => !empty($actualValue),
            'empty' => empty($actualValue),
            'equals' => $actualValue == $expectedValue,
            'not_equals' => $actualValue != $expectedValue,
            'greater_than' => $actualValue > $expectedValue,
            'less_than' => $actualValue < $expectedValue,
            'greater_or_equal' => $actualValue >= $expectedValue,
            'less_or_equal' => $actualValue <= $expectedValue,
            'contains' => str_contains($actualValue, $expectedValue),
            'not_contains' => !str_contains($actualValue, $expectedValue),
            'starts_with' => str_starts_with($actualValue, $expectedValue),
            'ends_with' => str_ends_with($actualValue, $expectedValue),
            'in' => in_array($actualValue, (array)$expectedValue),
            'not_in' => !in_array($actualValue, (array)$expectedValue),
            default => false,
        };
    }
    
    /**
     * Validate a single rule against a value
     */
    private function validateSingleRule($value, string $fieldType, string $ruleName, array $ruleProps): array
    {
        $errors = [];
        
        $result = match($ruleName) {
            'required' => $this->validateRequired($value),
            'min' => $this->validateMin($value, $fieldType, $ruleProps),
            'max' => $this->validateMax($value, $fieldType, $ruleProps),
            'email' => $this->validateEmail($value),
            'url' => $this->validateUrl($value),
            'numeric' => $this->validateNumeric($value),
            'integer' => $this->validateInteger($value),
            'alpha' => $this->validateAlpha($value),
            'alpha_num' => $this->validateAlphaNum($value),
            'alpha_dash' => $this->validateAlphaDash($value),
            'regex' => $this->validateRegex($value, $ruleProps),
            'in' => $this->validateIn($value, $ruleProps),
            'not_in' => $this->validateNotIn($value, $ruleProps),
            'date' => $this->validateDate($value),
            'date_format' => $this->validateDateFormat($value, $ruleProps),
            'before' => $this->validateBefore($value, $ruleProps),
            'after' => $this->validateAfter($value, $ruleProps),
            'before_or_equal' => $this->validateBeforeOrEqual($value, $ruleProps),
            'after_or_equal' => $this->validateAfterOrEqual($value, $ruleProps),
            'mimes' => $this->validateMimes($value, $ruleProps),
            'mimetypes' => $this->validateMimeTypes($value, $ruleProps),
            'size' => $this->validateSize($value, $ruleProps),
            'max_file_size' => $this->validateMaxFileSize($value, $ruleProps),
            'min_file_size' => $this->validateMinFileSize($value, $ruleProps),
            'dimensions' => $this->validateDimensions($value, $ruleProps),
            'between' => $this->validateBetween($value, $fieldType, $ruleProps),
            'confirmed' => $this->validateConfirmed($value, $ruleProps),
            'same' => $this->validateSame($value, $ruleProps),
            'different' => $this->validateDifferent($value, $ruleProps),
            'unique' => $this->validateUnique($value, $ruleProps),
            'starts_with' => $this->validateStartsWith($value, $ruleProps),
            'ends_with' => $this->validateEndsWith($value, $ruleProps),
            'json' => $this->validateJson($value),
            'latitude' => $this->validateLatitude($value),
            'longitude' => $this->validateLongitude($value),
            default => ['message' => "Unknown rule: {$ruleName}", 'valid' => false]
        };
        
        if (!$result['valid']) {
            $errors[] = $result['message'];
        }
        
        return $errors;
    }
    
    // ==================== VALIDATION METHODS ====================
    
    private function validateRequired($value): array
    {
        $valid = !empty($value) || $value === '0' || $value === 0;
        return [
            'valid' => $valid,
            'message' => $valid ? '' : 'This field is required.'
        ];
    }
    
    private function validateMin($value, string $fieldType, array $props): array
    {
        $min = $props['value'] ?? 0;
        
        if (in_array($fieldType, ['Number Input', 'Slider', 'Currency Input', 'Percentage Input', 'Rating'])) {
            $valid = is_numeric($value) && $value >= $min;
            $message = "Value must be at least {$min}.";
        } else {
            $valid = strlen($value) >= $min;
            $message = "Must be at least {$min} characters.";
        }
        
        return ['valid' => $valid, 'message' => $valid ? '' : $message];
    }
    
    private function validateMax($value, string $fieldType, array $props): array
    {
        $max = $props['value'] ?? 0;
        
        if (in_array($fieldType, ['Number Input', 'Slider', 'Currency Input', 'Percentage Input', 'Rating'])) {
            $valid = is_numeric($value) && $value <= $max;
            $message = "Value must not exceed {$max}.";
        } else {
            $valid = strlen($value) <= $max;
            $message = "Must not exceed {$max} characters.";
        }
        
        return ['valid' => $valid, 'message' => $valid ? '' : $message];
    }
    
    private function validateEmail($value): array
    {
        $valid = filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        return ['valid' => $valid, 'message' => $valid ? '' : 'Must be a valid email address.'];
    }
    
    private function validateUrl($value): array
    {
        $valid = filter_var($value, FILTER_VALIDATE_URL) !== false;
        return ['valid' => $valid, 'message' => $valid ? '' : 'Must be a valid URL.'];
    }
    
    private function validateNumeric($value): array
    {
        $valid = is_numeric($value);
        return ['valid' => $valid, 'message' => $valid ? '' : 'Must be a numeric value.'];
    }
    
    private function validateInteger($value): array
    {
        $valid = filter_var($value, FILTER_VALIDATE_INT) !== false;
        return ['valid' => $valid, 'message' => $valid ? '' : 'Must be an integer.'];
    }
    
    private function validateAlpha($value): array
    {
        $valid = preg_match('/^[a-zA-Z]+$/', $value);
        return ['valid' => (bool)$valid, 'message' => $valid ? '' : 'Must contain only letters.'];
    }
    
    private function validateAlphaNum($value): array
    {
        $valid = preg_match('/^[a-zA-Z0-9]+$/', $value);
        return ['valid' => (bool)$valid, 'message' => $valid ? '' : 'Must contain only letters and numbers.'];
    }
    
    private function validateAlphaDash($value): array
    {
        $valid = preg_match('/^[a-zA-Z0-9_-]+$/', $value);
        return ['valid' => (bool)$valid, 'message' => $valid ? '' : 'Must contain only letters, numbers, dashes and underscores.'];
    }
    
    private function validateRegex($value, array $props): array
    {
        $pattern = $props['pattern'] ?? '';
        $valid = preg_match($pattern, $value);
        return ['valid' => (bool)$valid, 'message' => $valid ? '' : 'Format is invalid.'];
    }
    
    private function validateIn($value, array $props): array
    {
        $options = $props['values'] ?? [];
        $valid = in_array($value, $options);
        return ['valid' => $valid, 'message' => $valid ? '' : 'Selected value is not valid.'];
    }
    
    private function validateNotIn($value, array $props): array
    {
        $options = $props['values'] ?? [];
        $valid = !in_array($value, $options);
        return ['valid' => $valid, 'message' => $valid ? '' : 'Selected value is not allowed.'];
    }
    
    private function validateDate($value): array
    {
        $valid = strtotime($value) !== false;
        return ['valid' => $valid, 'message' => $valid ? '' : 'Must be a valid date.'];
    }
    
    private function validateDateFormat($value, array $props): array
    {
        $format = $props['format'] ?? 'Y-m-d';
        $d = \DateTime::createFromFormat($format, $value);
        $valid = $d && $d->format($format) === $value;
        return ['valid' => $valid, 'message' => $valid ? '' : "Date must match format: {$format}"];
    }
    
    private function validateBefore($value, array $props): array
    {
        $beforeDate = $props['date'] ?? 'now';
        $valid = strtotime($value) < strtotime($beforeDate);
        return ['valid' => $valid, 'message' => $valid ? '' : "Date must be before {$beforeDate}."];
    }
    
    private function validateAfter($value, array $props): array
    {
        $afterDate = $props['date'] ?? 'now';
        $valid = strtotime($value) > strtotime($afterDate);
        return ['valid' => $valid, 'message' => $valid ? '' : "Date must be after {$afterDate}."];
    }
    
    private function validateBeforeOrEqual($value, array $props): array
    {
        $beforeDate = $props['date'] ?? 'now';
        $valid = strtotime($value) <= strtotime($beforeDate);
        return ['valid' => $valid, 'message' => $valid ? '' : "Date must be before or equal to {$beforeDate}."];
    }
    
    private function validateAfterOrEqual($value, array $props): array
    {
        $afterDate = $props['date'] ?? 'now';
        $valid = strtotime($value) >= strtotime($afterDate);
        return ['valid' => $valid, 'message' => $valid ? '' : "Date must be after or equal to {$afterDate}."];
    }
    
    private function validateMimes($value, array $props): array
    {
        // Value is expected to be file path or JSON with file info
        $allowedMimes = $props['types'] ?? [];
        $fileInfo = is_string($value) ? json_decode($value, true) : $value;
        $extension = $fileInfo['extension'] ?? pathinfo($value, PATHINFO_EXTENSION);
        
        $valid = in_array(strtolower($extension), array_map('strtolower', $allowedMimes));
        return ['valid' => $valid, 'message' => $valid ? '' : 'File type not allowed.'];
    }
    
    private function validateMimeTypes($value, array $props): array
    {
        $allowedTypes = $props['types'] ?? [];
        $fileInfo = is_string($value) ? json_decode($value, true) : $value;
        $mimeType = $fileInfo['mime_type'] ?? '';
        
        $valid = in_array($mimeType, $allowedTypes);
        return ['valid' => $valid, 'message' => $valid ? '' : 'File MIME type not allowed.'];
    }
    
    private function validateSize($value, array $props): array
    {
        $sizeKb = $props['size'] ?? 0;
        $fileInfo = is_string($value) ? json_decode($value, true) : $value;
        $fileSize = ($fileInfo['size'] ?? 0) / 1024; // Convert to KB
        
        $valid = $fileSize == $sizeKb;
        return ['valid' => $valid, 'message' => $valid ? '' : "File must be exactly {$sizeKb}KB."];
    }
    
    private function validateMaxFileSize($value, array $props): array
    {
        $maxSizeKb = $props['max_size'] ?? 0;
        $fileInfo = is_string($value) ? json_decode($value, true) : $value;
        $fileSize = ($fileInfo['size'] ?? 0) / 1024;
        
        $valid = $fileSize <= $maxSizeKb;
        return ['valid' => $valid, 'message' => $valid ? '' : "File must not exceed {$maxSizeKb}KB."];
    }
    
    private function validateMinFileSize($value, array $props): array
    {
        $minSizeKb = $props['min_size'] ?? 0;
        $fileInfo = is_string($value) ? json_decode($value, true) : $value;
        $fileSize = ($fileInfo['size'] ?? 0) / 1024;
        
        $valid = $fileSize >= $minSizeKb;
        return ['valid' => $valid, 'message' => $valid ? '' : "File must be at least {$minSizeKb}KB."];
    }
    
    private function validateDimensions($value, array $props): array
    {
        $fileInfo = is_string($value) ? json_decode($value, true) : $value;
        $width = $fileInfo['width'] ?? 0;
        $height = $fileInfo['height'] ?? 0;
        
        $checks = [];
        
        if (isset($props['min_width'])) {
            $checks[] = $width >= $props['min_width'];
        }
        if (isset($props['max_width'])) {
            $checks[] = $width <= $props['max_width'];
        }
        if (isset($props['min_height'])) {
            $checks[] = $height >= $props['min_height'];
        }
        if (isset($props['max_height'])) {
            $checks[] = $height <= $props['max_height'];
        }
        if (isset($props['width'])) {
            $checks[] = $width == $props['width'];
        }
        if (isset($props['height'])) {
            $checks[] = $height == $props['height'];
        }
        
        $valid = !in_array(false, $checks, true);
        return ['valid' => $valid, 'message' => $valid ? '' : 'Image dimensions do not meet requirements.'];
    }
    
    private function validateBetween($value, string $fieldType, array $props): array
    {
        $min = $props['min'] ?? 0;
        $max = $props['max'] ?? 0;
        
        if (in_array($fieldType, ['Number Input', 'Slider', 'Currency Input', 'Percentage Input'])) {
            $valid = is_numeric($value) && $value >= $min && $value <= $max;
            $message = "Value must be between {$min} and {$max}.";
        } else {
            $length = strlen($value);
            $valid = $length >= $min && $length <= $max;
            $message = "Length must be between {$min} and {$max} characters.";
        }
        
        return ['valid' => $valid, 'message' => $valid ? '' : $message];
    }
    
    private function validateConfirmed($value, array $props): array
    {
        $confirmationValue = $props['confirmation_value'] ?? '';
        $valid = $value === $confirmationValue;
        return ['valid' => $valid, 'message' => $valid ? '' : 'Confirmation does not match.'];
    }
    
    private function validateSame($value, array $props): array
    {
        $compareValue = $props['compare_value'] ?? '';
        $valid = $value === $compareValue;
        return ['valid' => $valid, 'message' => $valid ? '' : 'Values must match.'];
    }
    
    private function validateDifferent($value, array $props): array
    {
        $compareValue = $props['compare_value'] ?? '';
        $valid = $value !== $compareValue;
        return ['valid' => $valid, 'message' => $valid ? '' : 'Values must be different.'];
    }
    
    private function validateUnique($value, array $props): array
    {
        // Check uniqueness across entries
        $fieldId = $props['field_id'] ?? null;
        $excludeEntryId = $props['exclude_entry_id'] ?? null;
        
        if (!$fieldId) {
            return ['valid' => true, 'message' => ''];
        }
        
        $query = \App\Models\EntryValue::where('field_id', $fieldId)
            ->where('value', $value);
            
        if ($excludeEntryId) {
            $query->where('entry_id', '!=', $excludeEntryId);
        }
        
        $exists = $query->exists();
        return ['valid' => !$exists, 'message' => $exists ? 'This value has already been used.' : ''];
    }
    
    private function validateStartsWith($value, array $props): array
    {
        $prefixes = $props['values'] ?? [];
        $valid = false;
        
        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                $valid = true;
                break;
            }
        }
        
        return ['valid' => $valid, 'message' => $valid ? '' : 'Value must start with one of the specified prefixes.'];
    }
    
    private function validateEndsWith($value, array $props): array
    {
        $suffixes = $props['values'] ?? [];
        $valid = false;
        
        foreach ($suffixes as $suffix) {
            if (str_ends_with($value, $suffix)) {
                $valid = true;
                break;
            }
        }
        
        return ['valid' => $valid, 'message' => $valid ? '' : 'Value must end with one of the specified suffixes.'];
    }
    
    private function validateJson($value): array
    {
        json_decode($value);
        $valid = json_last_error() === JSON_ERROR_NONE;
        return ['valid' => $valid, 'message' => $valid ? '' : 'Must be valid JSON.'];
    }
    
    private function validateLatitude($value): array
    {
        $valid = is_numeric($value) && $value >= -90 && $value <= 90;
        return ['valid' => $valid, 'message' => $valid ? '' : 'Must be a valid latitude (-90 to 90).'];
    }
    
    private function validateLongitude($value): array
    {
        $valid = is_numeric($value) && $value >= -180 && $value <= 180;
        return ['valid' => $valid, 'message' => $valid ? '' : 'Must be a valid longitude (-180 to 180).'];
    }
}
