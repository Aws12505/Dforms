<?php

namespace App\Http\Controllers;

use App\Services\FieldTypeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FieldTypeController extends Controller
{
    protected $fieldTypeService;

    public function __construct(FieldTypeService $fieldTypeService)
    {
        $this->fieldTypeService = $fieldTypeService;
    }

    /**
     * Get all field types with their compatible input rules
     * GET /api/field-types
     */
    public function index(): JsonResponse
    {
        try {
            $fieldTypes = $this->fieldTypeService->getAllFieldTypesWithRules();

            return response()->json([
                'success' => true,
                'data' => $fieldTypes,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new field type
     * POST /api/field-types
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:100|unique:field_types,name',
            ]);

            $fieldType = $this->fieldTypeService->createFieldType($validated);

            return response()->json([
                'success' => true,
                'data' => $fieldType,
                'message' => 'Field type created successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get a specific field type
     * GET /api/field-types/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $fieldType = $this->fieldTypeService->getFieldTypeById($id);

            return response()->json([
                'success' => true,
                'data' => $fieldType,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Update a field type
     * PUT /api/field-types/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:100|unique:field_types,name,' . $id,
            ]);

            $fieldType = $this->fieldTypeService->updateFieldType($id, $validated);

            return response()->json([
                'success' => true,
                'data' => $fieldType,
                'message' => 'Field type updated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Delete a field type
     * DELETE /api/field-types/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->fieldTypeService->deleteFieldType($id);

            return response()->json([
                'success' => true,
                'message' => 'Field type deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
