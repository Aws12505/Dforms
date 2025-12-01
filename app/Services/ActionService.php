<?php

namespace App\Services;

use App\Models\Action;
use Illuminate\Support\Facades\DB;

class ActionService
{
    /**
     * Get all actions
     */
    public function getAllActions()
    {
        return Action::orderBy('name', 'asc')->get();
    }

    /**
     * Get only public actions (for overseer)
     */
    public function getPublicActions()
    {
        return Action::where('is_public', true)
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Create a new action
     */
    public function createAction(array $data)
    {
        DB::beginTransaction();
        try {
            // Set is_public default if not provided
            if (!isset($data['is_public'])) {
                $data['is_public'] = false;
            }

            $action = Action::create($data);

            DB::commit();
            return $action;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get a specific action by ID
     */
    public function getActionById(int $id)
    {
        return Action::findOrFail($id);
    }

    /**
     * Update an existing action
     */
    public function updateAction(int $id, array $data)
    {
        $action = Action::findOrFail($id);
        $action->update($data);
        return $action;
    }

    /**
     * Delete an action
     */
    public function deleteAction(int $id)
    {
        DB::beginTransaction();
        try {
            $action = Action::findOrFail($id);

            // Check if action is being used by any stage transition actions
            $usageCount = $action->stageTransitionActions()->count();

            if ($usageCount > 0) {
                throw new \Exception("Cannot delete this action. It is currently used by {$usageCount} stage transitions in forms.");
            }

            $action->delete();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
