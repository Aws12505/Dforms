<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Faker\Factory as Faker;

class BrimDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Run preprod/system seeder only if needed
        $needsPreprod =
            DB::table('languages')->count() === 0 ||
            DB::table('field_types')->count() === 0 ||
            DB::table('input_rules')->count() === 0 ||
            DB::table('actions')->count() === 0;

        if ($needsPreprod) {
            $this->call(SuperadminPreProductionSeeder::class);
        }

        DB::disableQueryLog();
        $faker = Faker::create();

        DB::transaction(function () use ($faker) {

            // ----------------------------
            // Load system data
            // ----------------------------
            $languages  = DB::table('languages')->get();
            $fieldTypes = DB::table('field_types')->get();
            $actions    = DB::table('actions')->get();

            // Map field_type_id => [input_rule_id,...]
            $ruleByFieldType = [];
            $pivot = DB::table('input_rule_field_types')->get();
            foreach ($pivot as $p) {
                $ruleByFieldType[$p->field_type_id][] = $p->input_rule_id;
            }

            // ----------------------------
            // Ensure exactly 1 local dev user for FK needs
            // ----------------------------
            $userId = DB::table('users')->value('id');
            if (!$userId) {
                $userId = 10001;
                DB::table('users')->insert([
                    'id' => $userId,
                    'name' => $faker->name(),
                    'email' => $faker->unique()->safeEmail(),
                    'default_language_id' => $languages->first()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // ----------------------------
            // 1 Category
            // ----------------------------
            $categoryId = DB::table('categories')->insertGetId([
                'name' => 'Sample Category',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ----------------------------
            // 1 Form
            // ----------------------------
            $formId = DB::table('forms')->insertGetId([
                'name' => 'Sample Form',
                'category_id' => $categoryId,
                'is_archived' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ----------------------------
            // 1 Version (published)
            // ----------------------------
            $versionId = DB::table('form_versions')->insertGetId([
                'form_id' => $formId,
                'version_number' => 0,
                'status' => 'published',
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Optional: 1 translation record if a non-default language exists
            $nonDefaultLang = $languages->firstWhere('is_default', 0);
            if ($nonDefaultLang) {
                DB::table('form_version_translations')->insert([
                    'form_version_id' => $versionId,
                    'language_id' => $nonDefaultLang->id,
                    'name' => 'Sample Form (translated)',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // ----------------------------
            // 1 Stage (initial)
            // ----------------------------
            $stageId = DB::table('stages')->insertGetId([
                'form_version_id' => $versionId,
                'name' => 'Initial Stage',
                'is_initial' => 1,
                'visibility_condition' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 1 Stage access rule
            DB::table('stage_access_rules')->insert([
                'stage_id' => $stageId,
                'allowed_users' => null,
                'allowed_roles' => null,
                'allowed_permissions' => null,
                'allow_authenticated_users' => 1,
                'email_field_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ----------------------------
            // 1 Section
            // ----------------------------
            $sectionId = DB::table('sections')->insertGetId([
                'stage_id' => $stageId,
                'name' => 'Main Section',
                'visibility_condition' => null,
                'order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ----------------------------
            // 1 Field
            // ----------------------------
            $fieldType = $fieldTypes->first(); // guaranteed from preprod
            $fieldId = DB::table('fields')->insertGetId([
                'section_id' => $sectionId,
                'field_type_id' => $fieldType->id,
                'label' => 'Sample Field',
                'placeholder' => null,
                'helper_text' => null,
                'default_value' => null,
                'visibility_condition' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Optional: 1 field translation if non-default language exists
            if ($nonDefaultLang) {
                DB::table('field_translations')->insert([
                    'field_id' => $fieldId,
                    'language_id' => $nonDefaultLang->id,
                    'label' => 'Sample Field (translated)',
                    'helper_text' => null,
                    'default_value' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // ----------------------------
            // 1 Field Rule (if compatible)
            // ----------------------------
            $compatibleRules = $ruleByFieldType[$fieldType->id] ?? [];
            if (!empty($compatibleRules)) {
                DB::table('field_rules')->insert([
                    'field_id' => $fieldId,
                    'input_rule_id' => $compatibleRules[0],
                    'rule_props' => null,
                    'rule_condition' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // ----------------------------
            // 1 Transition (to complete)
            // ----------------------------
            $transitionId = DB::table('stage_transitions')->insertGetId([
                'form_version_id' => $versionId,
                'from_stage_id' => $stageId,
                'to_stage_id' => null,
                'to_complete' => 1,
                'label' => 'Submit',
                'condition' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 1 Transition Action (if any actions exist)
            if ($actions->count() > 0) {
                DB::table('stage_transition_actions')->insert([
                    'stage_transition_id' => $transitionId,
                    'action_id' => $actions->first()->id,
                    'action_props' => json_encode([]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // ----------------------------
            // 1 Entry
            // ----------------------------
            $entryId = DB::table('entries')->insertGetId([
                'form_version_id' => $versionId,
                'current_stage_id' => $stageId,
                'public_identifier' => (string) Str::uuid(),
                'is_complete' => 0,
                'is_considered' => 0,
                'created_by_user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ----------------------------
            // 1 Entry Value
            // ----------------------------
            DB::table('entry_values')->insert([
                'entry_id' => $entryId,
                'field_id' => $fieldId,
                'value' => $this->fakeValueForFieldType($faker, $fieldTypes, $fieldType->id),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ----------------------------
            // 1 Notification
            // ----------------------------
            DB::table('notifications')->insert([
                'user_id' => $userId,
                'title' => 'Welcome',
                'body' => 'This is a sample notification.',
                'type' => 'info',
                'icon' => null,
                'link' => null,
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function fakeValueForFieldType($faker, $fieldTypes, int $fieldTypeId): string
    {
        $name = $fieldTypes->firstWhere('id', $fieldTypeId)->name ?? '';

        return match ($name) {
            'Text Input', 'Text Area' => $faker->sentence(),
            'Email Input' => $faker->unique()->safeEmail(),
            'Number Input', 'Currency Input', 'Percentage Input', 'Slider', 'Rating'
                => (string) $faker->numberBetween(0, 100),
            'Phone Input' => $faker->phoneNumber(),
            'Date Input' => $faker->date('Y-m-d'),
            'Time Input' => $faker->time('H:i'),
            'DateTime Input' => $faker->dateTime()->format('Y-m-d H:i:s'),
            'Checkbox', 'Toggle Switch' => $faker->boolean() ? '1' : '0',
            'Radio Button', 'Dropdown Select', 'Multi-Select' => $faker->word(),
            'URL Input' => $faker->url(),
            'Address Input' => json_encode([
                'street' => $faker->streetAddress(),
                'city' => $faker->city(),
                'state' => $faker->state(),
                'postal_code' => $faker->postcode(),
                'country' => $faker->country(),
            ]),
            'Location Picker' => json_encode([
                'lat' => $faker->latitude(),
                'lng' => $faker->longitude(),
                'address' => $faker->address(),
            ]),
            default => (string) $faker->sentence(),
        };
    }
}
