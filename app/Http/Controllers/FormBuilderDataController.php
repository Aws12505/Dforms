<?php

namespace App\Http\Controllers;

use App\Services\FieldTypeService;
use App\Services\ActionService;
use App\Services\InputRuleService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class FormBuilderDataController extends Controller
{
    protected $fieldTypeService;
    protected $actionService;
    protected $inputRuleService;

    public function __construct(
        FieldTypeService $fieldTypeService,
        ActionService $actionService,
        InputRuleService $inputRuleService
    ) {
        $this->fieldTypeService = $fieldTypeService;
        $this->actionService = $actionService;
        $this->inputRuleService = $inputRuleService;
    }

    /**
     * Get all data needed for form builder UI
     * GET /api/form-builder/data
     * 
     * Returns:
     * - Field types with their compatible rules
     * - Actions (public only)
     * - Users (from auth system)
     * - Roles (from auth system)
     * - Permissions (from auth system)
     */
    public function getFormBuilderData(): JsonResponse
    {
        try {
            $data = [
                'field_types' => $this->fieldTypeService->getAllFieldTypesWithRules(),
                'actions' => $this->actionService->getPublicActions(),
                'users' => $this->getUsers(),
                'roles' => $this->getRoles(),
                'permissions' => $this->getPermissions(),
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get users from auth system
     */
    private function getUsers(): array
    {
        return DB::table('users')
            ->select('id', 'name', 'email')
            ->orderBy('name', 'asc')
            ->get()
            ->toArray();
    }

    /**
     * Get roles from auth system
     */
    private function getRoles(): array
    {
        return DB::table('roles')
            ->select('id', 'name', 'description')
            ->orderBy('name', 'asc')
            ->get()
            ->toArray();
    }

    /**
     * Get permissions from auth system
     */
    private function getPermissions(): array
    {
        return DB::table('permissions')
            ->select('id', 'name', 'description')
            ->orderBy('name', 'asc')
            ->get()
            ->toArray();
    }

    /**
     * Get field types only
     * GET /api/form-builder/field-types
     */
    public function getFieldTypes(): JsonResponse
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
     * Get actions only
     * GET /api/form-builder/actions
     */
    public function getActions(): JsonResponse
    {
        try {
            $actions = $this->actionService->getPublicActions();

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
     * Get users, roles, and permissions
     * GET /api/form-builder/access-data
     */
    public function getAccessData(): JsonResponse
    {
        try {
            $data = [
                'users' => $this->getUsers(),
                'roles' => $this->getRoles(),
                'permissions' => $this->getPermissions(),
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
