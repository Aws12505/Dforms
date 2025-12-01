<?php

namespace App\Http\Controllers;

use App\Services\ActionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ActionController extends Controller
{
    protected $actionService;

    public function __construct(ActionService $actionService)
    {
        $this->actionService = $actionService;
    }

    /**
     * Get all actions
     * GET /api/actions
     */
    public function index(): JsonResponse
    {
        try {
            $actions = $this->actionService->getAllActions();

            return response()->json([
                'success' => true,
                'data' => $actions,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new action
     * POST /api/actions
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:100',
                'props_description' => 'required|string',
                'is_public' => 'boolean',
            ]);

            $action = $this->actionService->createAction($validated);

            return response()->json([
                'success' => true,
                'data' => $action,
                'message' => 'Action created successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get a specific action
     * GET /api/actions/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $action = $this->actionService->getActionById($id);

            return response()->json([
                'success' => true,
                'data' => $action,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Update an action
     * PUT /api/actions/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:100',
                'props_description' => 'sometimes|string',
                'is_public' => 'sometimes|boolean',
            ]);

            $action = $this->actionService->updateAction($id, $validated);

            return response()->json([
                'success' => true,
                'data' => $action,
                'message' => 'Action updated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Delete an action
     * DELETE /api/actions/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->actionService->deleteAction($id);

            return response()->json([
                'success' => true,
                'message' => 'Action deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
