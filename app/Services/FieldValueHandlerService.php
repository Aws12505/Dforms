<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class FieldValueHandlerService
{
    /**
     * Process and normalize field value based on field type
     * 
     * @param mixed $value Raw value from submission
     * @param string $fieldType Field type name
     * @return string Normalized value for storage
     */
    public function processFieldValue($value, string $fieldType): string
    {
        $method = 'handle' . str_replace(' ', '', $fieldType);
        
        if (method_exists($this, $method)) {
            return $this->$method($value);
        }
        
        // Default: convert to string
        return (string)$value;
    }
    
    // ==================== VALUE HANDLERS FOR EACH FIELD TYPE ====================
    
    private function handleTextInput($value): string
    {
        return trim((string)$value);
    }
    
    private function handleEmailInput($value): string
    {
        return strtolower(trim((string)$value));
    }
    
    private function handleNumberInput($value): string
    {
        return (string)filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }
    
    private function handlePhoneInput($value): string
    {
        // Store phone number as-is, validation already done
        return trim((string)$value);
    }
    
    private function handleTextArea($value): string
    {
        return trim((string)$value);
    }
    
    private function handleDateInput($value): string
    {
        // Normalize to Y-m-d format
        try {
            $date = new \DateTime($value);
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            return (string)$value;
        }
    }
    
    private function handleTimeInput($value): string
    {
        // Normalize to H:i:s format
        try {
            $time = new \DateTime($value);
            return $time->format('H:i:s');
        } catch (\Exception $e) {
            return (string)$value;
        }
    }
    
    private function handleDateTimeInput($value): string
    {
        // Normalize to Y-m-d H:i:s format
        try {
            $datetime = new \DateTime($value);
            return $datetime->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return (string)$value;
        }
    }
    
    private function handleCheckbox($value): string
    {
        // Normalize to 1 or 0
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true) ? '1' : '0';
    }
    
    private function handleRadioButton($value): string
    {
        return trim((string)$value);
    }
    
    private function handleDropdownSelect($value): string
    {
        return trim((string)$value);
    }
    
    private function handleMulti_Select($value): string
    {
        // Store as JSON array
        if (is_array($value)) {
            return json_encode($value);
        }
        
        if (is_string($value)) {
            // Check if already JSON
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
        }
        
        return json_encode([(string)$value]);
    }
    
    private function handleFileUpload($value): string
    {
        return $this->handleFileStorage($value);
    }
    
    private function handleImageUpload($value): string
    {
        return $this->handleFileStorage($value, 'images');
    }
    
    private function handleVideoUpload($value): string
    {
        return $this->handleFileStorage($value, 'videos');
    }
    
    private function handleDocumentUpload($value): string
    {
        return $this->handleFileStorage($value, 'documents');
    }
    
    private function handleURLInput($value): string
    {
        $url = trim((string)$value);
        
        // Add http:// if no protocol specified
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'http://' . $url;
        }
        
        return $url;
    }
    
    private function handlePasswordInput($value): string
    {
        // Hash password for storage
        return password_hash((string)$value, PASSWORD_DEFAULT);
    }
    
    private function handleColorPicker($value): string
    {
        // Normalize to hex format
        $color = trim((string)$value);
        
        if (!str_starts_with($color, '#')) {
            $color = '#' . $color;
        }
        
        return strtoupper($color);
    }
    
    private function handleRating($value): string
    {
        return (string)max(0, min(5, (int)$value));
    }
    
    private function handleSlider($value): string
    {
        return (string)filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }
    
    private function handleToggleSwitch($value): string
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true) ? '1' : '0';
    }
    
    private function handleCurrencyInput($value): string
    {
        // Remove currency symbols and normalize
        $cleaned = preg_replace('/[^0-9.-]/', '', (string)$value);
        return number_format((float)$cleaned, 2, '.', '');
    }
    
    private function handlePercentageInput($value): string
    {
        // Remove % symbol if present
        $cleaned = str_replace('%', '', (string)$value);
        return (string)filter_var($cleaned, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }
    
    private function handleSignaturePad($value): string
    {
        // Value is typically a base64 encoded image
        if (is_string($value) && str_starts_with($value, 'data:image')) {
            // Store as file
            return $this->handleBase64Image($value, 'signatures');
        }
        
        return (string)$value;
    }
    
    private function handleLocationPicker($value): string
    {
        // Store as JSON with lat/lng
        if (is_array($value)) {
            return json_encode([
                'lat' => (float)($value['lat'] ?? 0),
                'lng' => (float)($value['lng'] ?? 0),
                'address' => $value['address'] ?? ''
            ]);
        }
        
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode([
                    'lat' => (float)($decoded['lat'] ?? 0),
                    'lng' => (float)($decoded['lng'] ?? 0),
                    'address' => $decoded['address'] ?? ''
                ]);
            }
        }
        
        return json_encode(['lat' => 0, 'lng' => 0, 'address' => '']);
    }
    
    private function handleAddressInput($value): string
    {
        // Store as JSON with address components
        if (is_array($value)) {
            return json_encode([
                'street' => $value['street'] ?? '',
                'city' => $value['city'] ?? '',
                'state' => $value['state'] ?? '',
                'postal_code' => $value['postal_code'] ?? '',
                'country' => $value['country'] ?? ''
            ]);
        }
        
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode([
                    'street' => $decoded['street'] ?? '',
                    'city' => $decoded['city'] ?? '',
                    'state' => $decoded['state'] ?? '',
                    'postal_code' => $decoded['postal_code'] ?? '',
                    'country' => $decoded['country'] ?? ''
                ]);
            }
        }
        
        return json_encode([
            'street' => '',
            'city' => '',
            'state' => '',
            'postal_code' => '',
            'country' => ''
        ]);
    }
    
    // ==================== HELPER METHODS ====================
    
    private function handleFileStorage($value, string $directory = 'files'): string
    {
        if ($value instanceof UploadedFile) {
            $path = $value->store($directory, 'public');
            
            return json_encode([
                'path' => $path,
                'original_name' => $value->getClientOriginalName(),
                'mime_type' => $value->getMimeType(),
                'size' => $value->getSize(),
                'extension' => $value->getClientOriginalExtension()
            ]);
        }
        
        // If already a path or JSON
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
            
            // Assume it's a file path
            return json_encode(['path' => $value]);
        }
        
        return json_encode([]);
    }
    
    private function handleBase64Image(string $base64, string $directory = 'images'): string
    {
        // Extract base64 data
        if (preg_match('/^data:image\/(\w+);base64,/', $base64, $type)) {
            $base64 = substr($base64, strpos($base64, ',') + 1);
            $extension = strtolower($type[1]);
            
            $decoded = base64_decode($base64);
            
            if ($decoded === false) {
                return '';
            }
            
            $filename = uniqid() . '.' . $extension;
            $path = "{$directory}/{$filename}";
            
            Storage::disk('public')->put($path, $decoded);
            
            return json_encode([
                'path' => $path,
                'mime_type' => "image/{$extension}",
                'size' => strlen($decoded)
            ]);
        }
        
        return '';
    }
}
