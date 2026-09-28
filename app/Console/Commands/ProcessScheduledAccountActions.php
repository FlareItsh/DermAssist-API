<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Diagnosis;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessScheduledAccountActions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'patients:process-scheduled-actions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending doctor-scheduled patient account actions and unverified dummy accounts';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $count = self::processDueActions();
        $prunedCount = self::pruneExpiredTrash();
        $this->info("Processed {$count} pending scheduled account actions. Permanently pruned {$prunedCount} dummy accounts.");
    }

    /**
     * Helper to process due account actions in real-time.
     */
    public static function processDueActions(): int
    {
        $count = 0;

        // 1. Process Doctor-scheduled actions (disable / delete)
        $scheduledUsers = User::whereNotNull('account_action')
            ->whereNotNull('account_action_scheduled_at')
            ->where('account_action_scheduled_at', '<=', now())
            ->get();

        foreach ($scheduledUsers as $user) {
            try {
                if ($user->account_action === 'disable') {
                    $user->update([
                        'account_status' => 'disabled',
                        'account_action' => null,
                        'account_action_scheduled_at' => null,
                    ]);
                    $user->tokens()->delete(); // Log them out immediately
                    $count++;
                } elseif ($user->account_action === 'delete') {
                    $user->tokens()->delete(); // Log them out immediately
                    Conversation::where('patient_id', $user->id)->orWhere('doctor_id', $user->id)->delete();
                    Appointment::where('patient_id', $user->id)->orWhere('doctor_id', $user->id)->delete();
                    Diagnosis::where('user_uuid', $user->uuid)->delete();
                    $user->forceDelete();
                    $count++;
                }
            } catch (\Throwable $e) {
                Log::error("Failed to process scheduled action for user {$user->id}: ".$e->getMessage());
            }
        }

        // 2. Process Overdue Unverified Accounts (Soft-Delete)
        $unverifiedOverdueUsers = User::where('account_status', 'pending_verification')
            ->whereNotNull('verification_deadline')
            ->where('verification_deadline', '<=', now())
            ->get();

        foreach ($unverifiedOverdueUsers as $user) {
            try {
                $user->tokens()->delete();
                $user->delete(); // Eloquent Soft Delete (sets deleted_at)
                $count++;
            } catch (\Throwable $e) {
                Log::error("Failed to soft delete overdue unverified user {$user->id}: ".$e->getMessage());
            }
        }

        return $count;
    }

    /**
     * Permanently delete (forceDelete) soft-deleted unverified/dummy accounts older than the retention period.
     */
    public static function pruneExpiredTrash(int $retentionDays = 14): int
    {
        $prunedCount = 0;
        $trashedUsers = User::onlyTrashed()
            ->where('account_status', 'pending_verification')
            ->where('deleted_at', '<=', now()->subDays($retentionDays))
            ->get();

        foreach ($trashedUsers as $user) {
            try {
                Conversation::where('patient_id', $user->id)->orWhere('doctor_id', $user->id)->delete();
                Appointment::where('patient_id', $user->id)->orWhere('doctor_id', $user->id)->delete();
                Diagnosis::where('user_uuid', $user->uuid)->delete();
                $user->forceDelete();
                $prunedCount++;
            } catch (\Throwable $e) {
                Log::error("Failed to permanently delete trashed user {$user->id}: ".$e->getMessage());
            }
        }

        return $prunedCount;
    }
}
