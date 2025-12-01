<?php

namespace App\Http\Controllers;

use App\Services\FieldTypeFilterService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FieldTypeFilterController extends Controller
{
    protected $fieldTypeFilterService;

    public function __construct(FieldTypeFilterService $fieldTypeFilterService)
    {
        $this->fieldTypeFilterService = $fieldTypeFilterService;
    }

    /**
     * Get all field type filters
     * GET /api/field-type-filters
     */
    public function index(): JsonResponse
    {
        try {
            $filters = $this->fieldTypeFilterService->getAllFieldTypeFilters();

            return response()->json([
                'success' => true,
                'data' => $filters,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new field type filter
     * POST /api/field-type-filters
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'field_type_id' => 'required|exists:field_types,id',
                'filter_method_description' => 'required|string',
            ]);

            $filter = $this->fieldTypeFilterService->createFieldTypeFilter($validated);

            return response()->json([
                'success' => true,
                'data' => $filter,
                'message' => 'Field type filter created successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get a specific field type filter
     * GET /api/field-type-filters/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $filter = $this->fieldTypeFilterService->getFieldTypeFilterById($id);

            return response()->json([
                'success' => true,
                'data' => $filter,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Update a field type filter
     * PUT /api/field-type-filters/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'field_type_id' => 'sometimes|exists:field_types,id',
                'filter_method_description' => 'sometimes|string',
            ]);

            $filter = $this->fieldTypeFilterService->updateFieldTypeFilter($id, $validated);

            return response()->json([
                'success' => true,
                'data' => $filter,
                'message' => 'Field type filter updated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Delete a field type filter
     * DELETE /api/field-type-filters/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->fieldTypeFilterService->deleteFieldTypeFilter($id);

            return response()->json([
                'success' => true,
                'message' => 'Field type filter deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
