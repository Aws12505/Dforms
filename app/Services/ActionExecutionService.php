<?php

namespace App\Services;

use App\Models\Action;
use App\Models\StageTransitionAction;
use App\Models\Entry;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ActionExecutionService
{
    /**
     * Execute all actions attached to a stage transition
     * 
     * @param int $stageTransitionId
     * @param Entry $entry
     * @return array Results of action executions
     */
    public function executeTransitionActions(int $stageTransitionId, Entry $entry): array
    {
        $actions = StageTransitionAction::with('action')
            ->where('stage_transition_id', $stageTransitionId)
            ->get();
        
        $results = [];
        
        foreach ($actions as $transitionAction) {
            $result = $this->executeAction(
                $transitionAction->action->name,
                $transitionAction->action_props,
                $entry
            );
            
            $results[] = [
                'action_name' => $transitionAction->action->name,
                'success' => $result['success'],
                'message' => $result['message']
            ];
            
            // Log action execution
            Log::info("Action executed", [
                'action' => $transitionAction->action->name,
                'entry_id' => $entry->id,
                'success' => $result['success']
            ]);
        }
        
        return $results;
    }
    
    /**
     * Execute a single action
     * 
     * @param string $actionName
     * @param array $props
     * @param Entry $entry
     * @return array
     */
    private function executeAction(string $actionName, array $props, Entry $entry): array
    {
        $method = 'execute' . str_replace(' ', '', $actionName);
        
        if (method_exists($this, $method)) {
            try {
                return $this->$method($props, $entry);
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'message' => "Failed to execute action: " . $e->getMessage()
                ];
            }
        }
        
        return [
            'success' => false,
            'message' => "Action handler not found: {$actionName}"
        ];
    }
    
    // ==================== ACTION EXECUTION METHODS ====================
    
    private function executeSendEmail(array $props, Entry $entry): array
    {
        $subject = $this->replaceVariables($props['email_subject'] ?? 'Form Submission', $entry);
        $content = $this->replaceVariables($props['email_content'] ?? '', $entry);
        $attachments = $props['email_attachments'] ?? [];
        $ccEmails = $props['cc_emails'] ?? [];
        $bccEmails = $props['bcc_emails'] ?? [];
        
        // Collect recipients
        $recipients = $this->collectRecipients($props, $entry);
        
        if (empty($recipients)) {
            return [
                'success' => false,
                'message' => 'No recipients specified or found'
            ];
        }
        
        // Send email to each recipient
        foreach ($recipients as $recipient) {
            Mail::send([], [], function ($message) use ($recipient, $subject, $content, $attachments, $ccEmails, $bccEmails) {
                $message->to($recipient)
                    ->subject($subject)
                    ->html($content);
                
                // Attach files
                foreach ($attachments as $attachment) {
                    if (is_string($attachment) && file_exists($attachment)) {
                        $message->attach($attachment);
                    }
                }
                
                // Handle CC
                if (!empty($ccEmails)) {
                    $message->cc($ccEmails);
                }
                
                // Handle BCC
                if (!empty($bccEmails)) {
                    $message->bcc($bccEmails);
                }
            });
        }
        
        return [
            'success' => true,
            'message' => "Email sent to " . count($recipients) . " recipient(s)"
        ];
    }
    
    private function executeSendNotification(array $props, Entry $entry): array
{
    $title = $this->replaceVariables($props['notification_title'] ?? 'Notification', $entry);
    $body = $this->replaceVariables($props['notification_body'] ?? '', $entry);
    $type = $props['notification_type'] ?? 'info';
    $icon = $props['notification_icon'] ?? null;
    $link = $this->replaceVariables($props['notification_link'] ?? '', $entry);
    
    // Collect recipients
    $recipients = $this->collectRecipients($props, $entry);
    
    if (empty($recipients)) {
        return [
            'success' => false,
            'message' => 'No recipients specified or found'
        ];
    }
    
    // Get User models for recipients
    $users = User::whereIn('email', $recipients)->get();
    
    if ($users->isEmpty()) {
        return [
            'success' => false,
            'message' => 'No valid users found for notification'
        ];
    }
    
    // Create notification records
    foreach ($users as $user) {
        \App\Models\Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'icon' => $icon,
            'link' => $link,
            'read_at' => null
        ]);
    }
    
    return [
        'success' => true,
        'message' => "Notification sent to " . $users->count() . " user(s)"
    ];
}

    
    private function executeCallWebhook(array $props, Entry $entry): array
    {
        $url = $props['webhook_url'] ?? '';
        $method = strtolower($props['webhook_method'] ?? 'post');
        $headers = $props['webhook_headers'] ?? [];
        $payload = $this->replaceVariablesInArray($props['webhook_payload'] ?? [], $entry);
        $timeout = $props['webhook_timeout'] ?? 30;
        
        if (empty($url)) {
            return [
                'success' => false,
                'message' => 'Webhook URL not specified'
            ];
        }
        
        try {
            $httpClient = Http::timeout($timeout)->withHeaders($headers);
            
            // Call the appropriate HTTP method
            $response = match($method) {
                'get' => $httpClient->get($url, $payload),
                'post' => $httpClient->post($url, $payload),
                'put' => $httpClient->put($url, $payload),
                'patch' => $httpClient->patch($url, $payload),
                'delete' => $httpClient->delete($url, $payload),
                default => $httpClient->post($url, $payload)
            };
            
            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => "Webhook called successfully. Status: {$response->status()}"
                ];
            } else {
                return [
                    'success' => false,
                    'message' => "Webhook failed. Status: {$response->status()}, Body: {$response->body()}"
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => "Webhook error: " . $e->getMessage()
            ];
        }
    }
    
    // ==================== HELPER METHODS ====================
    
    /**
     * Collect all recipient email addresses based on props
     */
    private function collectRecipients(array $props, Entry $entry): array
    {
        $recipients = [];
        
        // Direct email addresses
        if (!empty($props['receivers_emails'])) {
            $recipients = array_merge($recipients, $props['receivers_emails']);
        }
        
        // Specific users
        if (!empty($props['receivers_users'])) {
            $userEmails = User::whereIn('id', $props['receivers_users'])
                ->pluck('email')
                ->toArray();
            $recipients = array_merge($recipients, $userEmails);
        }
        
        // Users with roles
        if (!empty($props['receivers_roles'])) {
            $userEmails = User::whereHas('roles', function ($query) use ($props) {
                $query->whereIn('roles.id', $props['receivers_roles']);
            })->pluck('email')->toArray();
            $recipients = array_merge($recipients, $userEmails);
        }
        
        // Users with permissions
        if (!empty($props['receivers_permissions'])) {
            $userEmails = User::whereHas('permissions', function ($query) use ($props) {
                $query->whereIn('permissions.id', $props['receivers_permissions']);
            })->pluck('email')->toArray();
            $recipients = array_merge($recipients, $userEmails);
        }
        
        return array_unique($recipients);
    }
    
    /**
     * Replace variables in string with entry data
     */
    private function replaceVariables(string $text, Entry $entry): string
    {
        $entry->load(['formVersion.form', 'entryValues.field', 'creator']);
        
        // Generate entry link using the existing route
        $entryLink = url("/enduser/entries/{$entry->public_identifier}");
        
        $variables = [
            '{{entry_link}}' => $entryLink,
            '{{form_name}}' => $entry->formVersion->form->name,
            '{{user_name}}' => $entry->creator->name ?? 'Unknown',
            '{{user_email}}' => $entry->creator->email ?? '',
            '{{entry_id}}' => $entry->id,
            '{{public_identifier}}' => $entry->public_identifier,
            '{{current_stage}}' => $entry->currentStage->name ?? '',
            '{{created_at}}' => $entry->created_at->format('Y-m-d H:i:s'),
        ];
        
        // Add field values as variables
        foreach ($entry->entryValues as $entryValue) {
            $fieldLabel = $entryValue->field->label;
            $fieldKey = '{{field_' . str_replace(' ', '_', strtolower($fieldLabel)) . '}}';
            $variables[$fieldKey] = $entryValue->value;
        }
        
        return str_replace(array_keys($variables), array_values($variables), $text);
    }
    
    /**
     * Replace variables in array recursively
     */
    private function replaceVariablesInArray(array $data, Entry $entry): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->replaceVariables($value, $entry);
            } elseif (is_array($value)) {
                $data[$key] = $this->replaceVariablesInArray($value, $entry);
            }
        }
        
        return $data;
    }
}
