<?php

namespace App\Http\Controllers;

use App\Services\EndUserFormService;
use App\Http\Requests\EndUser\GetAvailableFormsRequest;
use App\Http\Requests\EndUser\GetFormStructureRequest;
use App\Http\Requests\EndUser\SubmitInitialStageRequest;
use App\Http\Requests\EndUser\GetEntryByPublicIdentifierRequest;
use App\Http\Requests\EndUser\SubmitLaterStageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
class EndUserFormController extends Controller
{
    protected EndUserFormService $endUserFormService;

    public function __construct(EndUserFormService $endUserFormService)
    {
        $this->endUserFormService = $endUserFormService;
    }

    /**
     * GET /api/enduser/forms
     * Get available published forms (localized)
     */
    public function getAvailableForms(GetAvailableFormsRequest $request): JsonResponse
    {
        try {
            $forms = $this->endUserFormService->getAvailableFormsForUser(
                Auth::id(),
                $request->input('language_id')
            );

            return response()->json([
                'success' => true,
                'data' => $forms,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve forms.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * GET /api/enduser/forms/{formVersionId}/structure
     * Get form structure (localized, initial stage only)
     */
    public function getFormStructure(GetFormStructureRequest $request): JsonResponse
    {
        try {
            $structure = $this->endUserFormService->getFormStructure(
                $request->input('form_version_id'),
                Auth::id(), // FIXED: Added missing userId parameter
                $request->input('language_id')
            );

            return response()->json([
                'success' => true,
                'data' => $structure,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve form structure.',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * POST /api/enduser/forms/submit-initial
     * Submit initial stage
     */
    public function submitInitialStage(SubmitInitialStageRequest $request): JsonResponse
    {
        try {
            // FIXED: Pass correct parameters to service
            $result = $this->endUserFormService->submitInitialStage(
                $request->input('form_version_id'),
                $request->input('field_values'),
                $request->input('stage_transition_id'), // FIXED: Added transition ID
                Auth::id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Initial stage submitted successfully.',
                'data' => $result,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit initial stage.',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),      // ADD THIS
                'line' => $e->getLine(),
            ], 400);
        }
    }

    /**
     * GET /api/enduser/entries/{publicIdentifier}
     * Get entry by public identifier (for later stage)
     */
    public function getEntryByPublicIdentifier(GetEntryByPublicIdentifierRequest $request): JsonResponse
    {
        try {
            $entry = $this->endUserFormService->getEntryByPublicIdentifier(
                $request->input('public_identifier'),
                Auth::id(), // FIXED: Added missing userId parameter
                $request->input('language_id')
            );

            return response()->json([
                'success' => true,
                'data' => $entry,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Entry not found.',
                'error' => $e->getMessage(),
            ], 404);
        }
    }


    /**
     * POST /api/enduser/entries/submit-later-stage
     * Submit later stage
     */
    public function submitLaterStage(SubmitLaterStageRequest $request): JsonResponse
    {
        try {
            // FIXED: Pass correct parameters to service
            $result = $this->endUserFormService->submitLaterStage(
                $request->input('public_identifier'),
                $request->input('field_values'),
                $request->input('stage_transition_id'),
                Auth::id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Later stage submitted successfully.',
                'data' => $result,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit later stage.',
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
