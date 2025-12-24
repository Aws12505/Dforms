<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SuperadminPreProductionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * This seeder populates all pre-production data that should be ready
     * before the system goes live. This includes:
     * - Languages (with default language)
     * - Field Types
     * - Input Rules (with field type associations)
     * - Actions
     * - Field Type Filters
     */
    public function run(): void
    {
        $this->seedLanguages();
        $this->seedFieldTypes();
        $this->seedInputRules();
        $this->seedActions();
        $this->seedFieldTypeFilters();
    }

    /**
     * Seed system languages
     */
    private function seedLanguages(): void
    {
        $languages = [
            ['code' => 'en', 'name' => 'English', 'is_default' => true],
            ['code' => 'ar', 'name' => 'Arabic', 'is_default' => false],
            ['code' => 'es', 'name' => 'Spanish', 'is_default' => false],
        ];

        foreach ($languages as $language) {
            DB::table('languages')->insert([
                'code' => $language['code'],
                'name' => $language['name'],
                'is_default' => $language['is_default'],
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
    }

    /**
     * Seed field types
     * Each field type should have backend logic and frontend component implemented
     */
    private function seedFieldTypes(): void
    {
        $fieldTypes = [
            'Text Input',
            'Email Input',
            'Number Input',
            'Phone Input',
            'Text Area',
            'Date Input',
            'Time Input',
            'DateTime Input',
            'Checkbox',
            'Radio Button',
            'Dropdown Select',
            'Multi_Select',
            'File Upload',
            'Image Upload',
            'Video Upload',
            'Document Upload',
            'URL Input',
            'Password Input',
            'Color Picker',
            'Rating',
            'Slider',
            'Toggle Switch',
            'Currency Input',
            'Percentage Input',
            'Signature Pad',
            'Location Picker',
            'Address Input',
            'Voice Record'
        ];

        foreach ($fieldTypes as $fieldType) {
            DB::table('field_types')->insert([
                'name' => $fieldType,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
    }

    /**
     * Seed input rules with their field type associations
     */
    private function seedInputRules(): void
    {
        // Define rules with their properties
        $rules = [
            [
                'name' => 'required',
                'description' => 'Field must have a value',
                'is_public' => true,
                'applies_to' => 'all', // Will be applied to all field types
            ],
            [
                'name' => 'min',
                'description' => 'Minimum length (for text) or minimum value (for numbers)',
                'is_public' => true,
                'applies_to' => [1, 3, 5, 17, 21, 23, 24], // Text, Email, Number, Phone, TextArea, Date, URL, Dropdown, Currency, Percentage
            ],
            [
                'name' => 'max',
                'description' => 'Maximum length (for text) or maximum value (for numbers)',
                'is_public' => true,
                'applies_to' => [1, 3, 5, 17, 21, 23, 24],
            ],
            [
                'name' => 'url',
                'description' => 'Must be a valid URL',
                'is_public' => true,
                'applies_to' => [17], // URL Input
            ],
            [
                'name' => 'numeric',
                'description' => 'Must be a numeric value',
                'is_public' => true,
                'applies_to' => [3, 21, 24], // Number, Slider, Currency, Percentage
            ],
            [
                'name' => 'integer',
                'description' => 'Must be an integer value',
                'is_public' => true,
                'applies_to' => [3, 21], // Number, Slider
            ],
            [
                'name' => 'alpha',
                'description' => 'Must contain only alphabetic characters',
                'is_public' => true,
                'applies_to' => [1, 5], // Text Input, Text Area
            ],
            [
                'name' => 'alpha_num',
                'description' => 'Must contain only alphanumeric characters',
                'is_public' => true,
                'applies_to' => [1, 5],
            ],
            [
                'name' => 'alpha_dash',
                'description' => 'Must contain only alphanumeric characters, dashes, and underscores',
                'is_public' => true,
                'applies_to' => [1, 5],
            ],
            [
                'name' => 'regex',
                'description' => 'Must match a specific regular expression pattern',
                'is_public' => true,
                'applies_to' => [1, 2, 4, 5], // Text, Email, Phone, TextArea
            ],
            [
                'name' => 'before',
                'description' => 'Date must be before a specified date',
                'is_public' => true,
                'applies_to' => [6, 8],
            ],
            [
                'name' => 'after',
                'description' => 'Date must be after a specified date',
                'is_public' => true,
                'applies_to' => [6, 8],
            ],
            [
                'name' => 'before_or_equal',
                'description' => 'Date must be before or equal to a specified date',
                'is_public' => true,
                'applies_to' => [6, 8],
            ],
            [
                'name' => 'after_or_equal',
                'description' => 'Date must be after or equal to a specified date',
                'is_public' => true,
                'applies_to' => [6, 8],
            ],
            [
                'name' => 'mimetypes',
                'description' => 'File must match specified MIME type patterns',
                'is_public' => true,
                'applies_to' => [13, 14, 15, 16],
            ],
            [
                'name' => 'max_file_size',
                'description' => 'Maximum file size in kilobytes (e.g., 30MB = 30720)',
                'is_public' => true,
                'applies_to' => [13, 14, 15, 16, 28],
            ],
            [
                'name' => 'min_file_size',
                'description' => 'Minimum file size in kilobytes',
                'is_public' => true,
                'applies_to' => [13, 14, 15, 16],
            ],
            // [
            //     'name' => 'dimensions',
            //     'description' => 'Image must meet dimension requirements (width, height, min_width, etc.)',
            //     'is_public' => true,
            //     'applies_to' => [14], // Image Upload
            // ],
            [
                'name' => 'between',
                'description' => 'Value must be between two values (for numbers or string length)',
                'is_public' => true,
                'applies_to' => [1, 3, 5, 21, 23, 24],
            ],
            [
                'name' => 'same',
                'description' => 'Field must match another field',
                'is_public' => true,
                'applies_to' => [1, 2, 3, 18],
            ],
            [
                'name' => 'different',
                'description' => 'Field must be different from another field',
                'is_public' => true,
                'applies_to' => [1, 2, 3, 18],
            ],
            [
                'name' => 'unique',
                'description' => 'Value must be unique across all entries',
                'is_public' => true,
                'applies_to' => [1, 2, 4, 17],
            ],
            [
                'name' => 'starts_with',
                'description' => 'String must start with specified value(s)',
                'is_public' => true,
                'applies_to' => [1, 2, 4, 5, 17],
            ],
            [
                'name' => 'ends_with',
                'description' => 'String must end with specified value(s)',
                'is_public' => true,
                'applies_to' => [1, 2, 5, 17],
            ],
            [
                'name' => 'json',
                'description' => 'Field must be valid JSON',
                'is_public' => false,
                'applies_to' => [5], // TextArea
            ],
        ];

        // Get all field type IDs for "applies to all" rules
        $allFieldTypeIds = DB::table('field_types')->pluck('id')->toArray();

        foreach ($rules as $rule) {
            // Insert the rule
            $ruleId = DB::table('input_rules')->insertGetId([
                'name' => $rule['name'],
                'description' => $rule['description'],
                'is_public' => $rule['is_public'],
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            // Determine which field types this rule applies to
            $fieldTypeIds = $rule['applies_to'] === 'all' 
                ? $allFieldTypeIds 
                : $rule['applies_to'];

            // Create associations with field types
            foreach ($fieldTypeIds as $fieldTypeId) {
                DB::table('input_rule_field_types')->insert([
                    'input_rule_id' => $ruleId,
                    'field_type_id' => $fieldTypeId,
                ]);
            }
        }
    }

    /**
     * Seed actions that can be triggered on stage transitions
     */
    private function seedActions(): void
    {
        $actions = [
            [
                'name' => 'Send Email',
                'props_description' => json_encode([
                    'email_subject' => 'Subject of the email',
                    'email_content' => 'Body content of the email (supports variables like {entry_link}, {form_name}, {user_name})',
                    'email_attachments' => 'Array of file paths to attach',
                    'receivers_users' => 'Array of specific user IDs to receive the email',
                    'receivers_roles' => 'Array of role IDs - all users with these roles will receive the email',
                    'receivers_permissions' => 'Array of permission IDs - all users with these permissions will receive the email',
                    'receivers_emails' => 'Array of specific email addresses',
                    'cc_users' => 'Array of user IDs to CC',
                    'cc_emails' => 'Array of email addresses to CC',
                    'bcc_users' => 'Array of user IDs to BCC',
                    'bcc_emails' => 'Array of email addresses to BCC',
                ]),
                'is_public' => true,
            ],
            [
                'name' => 'Send Notification',
                'props_description' => json_encode([
                    'notification_title' => 'Title of the notification',
                    'notification_body' => 'Body content of the notification (supports variables)',
                    'notification_type' => 'Type: info, success, warning, error',
                    'notification_icon' => 'Icon to display',
                    'notification_link' => 'Link to navigate when notification is clicked',
                    'receivers_users' => 'Array of specific user IDs to receive notification',
                    'receivers_roles' => 'Array of role IDs - all users with these roles will receive',
                    'receivers_permissions' => 'Array of permission IDs - all users with these permissions will receive',
                ]),
                'is_public' => true,
            ],
            [
                'name' => 'Call Webhook',
                'props_description' => json_encode([
                    'webhook_url' => 'URL to send the webhook request to',
                    'webhook_method' => 'HTTP method: GET, POST, PUT, PATCH, DELETE',
                    'webhook_headers' => 'Custom headers to include',
                    'webhook_payload' => 'Data to send (supports variables)',
                    'webhook_timeout' => 'Request timeout in seconds',
                ]),
                'is_public' => true,
            ],
        ];

        foreach ($actions as $action) {
            DB::table('actions')->insert([
                'name' => $action['name'],
                'props_description' => $action['props_description'],
                'is_public' => $action['is_public'],
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
    }

    /**
     * Seed field type filters - defines how entries can be filtered by field type
     */
    private function seedFieldTypeFilters(): void
    {
        $filters = [
            [
                'field_type_id' => 1, // Text Input
                'filter_method_description' => 'Text search (contains, equals, starts with, ends with)',
            ],
            [
                'field_type_id' => 2, // Email Input
                'filter_method_description' => 'Email search (contains, equals, domain filter)',
            ],
            [
                'field_type_id' => 3, // Number Input
                'filter_method_description' => 'Numeric range (equals, greater than, less than, between)',
            ],
            [
                'field_type_id' => 4, // Phone Input
                'filter_method_description' => 'Phone search (contains, country code filter)',
            ],
            [
                'field_type_id' => 6, // Date Input
                'filter_method_description' => 'Date range (equals, before, after, between)',
            ],
            [
                'field_type_id' => 7, // Time Input
                'filter_method_description' => 'Time range (equals, before, after, between)',
            ],
            [
                'field_type_id' => 8, // DateTime Input
                'filter_method_description' => 'DateTime range (equals, before, after, between)',
            ],
            [
                'field_type_id' => 9, // Checkbox
                'filter_method_description' => 'Boolean filter (checked, unchecked)',
            ],
            [
                'field_type_id' => 10, // Radio Button
                'filter_method_description' => 'Select from available options',
            ],
            [
                'field_type_id' => 11, // Dropdown Select
                'filter_method_description' => 'Select from available options (single or multiple)',
            ],
            [
                'field_type_id' => 12, // Multi-Select
                'filter_method_description' => 'Contains any of selected options',
            ],
            [
                'field_type_id' => 13, // File Upload
                'filter_method_description' => 'File type filter, has attachment filter',
            ],
            [
                'field_type_id' => 14, // Image Upload
                'filter_method_description' => 'Has image filter, image type filter',
            ],
            [
                'field_type_id' => 15, // Video Upload
                'filter_method_description' => 'Has video filter, video format filter',
            ],
            [
                'field_type_id' => 16, // Document Upload
                'filter_method_description' => 'Document type filter (PDF, Word, Excel, etc.)',
            ],
            [
                'field_type_id' => 17, // URL Input
                'filter_method_description' => 'URL search (contains, domain filter)',
            ],
            [
                'field_type_id' => 20, // Rating
                'filter_method_description' => 'Rating range (equals, greater than, less than)',
            ],
            [
                'field_type_id' => 21, // Slider
                'filter_method_description' => 'Value range (equals, greater than, less than, between)',
            ],
            [
                'field_type_id' => 22, // Toggle Switch
                'filter_method_description' => 'Boolean filter (on, off)',
            ],
            [
                'field_type_id' => 23, // Currency Input
                'filter_method_description' => 'Currency amount range (equals, greater than, less than, between)',
            ],
            [
                'field_type_id' => 24, // Percentage Input
                'filter_method_description' => 'Percentage range (equals, greater than, less than, between)',
            ],
            [
                'field_type_id' => 27, // Address Input
                'filter_method_description' => 'Address search (city, state, country, postal code)',
            ],
        ];

        foreach ($filters as $filter) {
            DB::table('field_type_filters')->insert([
                'field_type_id' => $filter['field_type_id'],
                'filter_method_description' => $filter['filter_method_description'],
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
    }
}
