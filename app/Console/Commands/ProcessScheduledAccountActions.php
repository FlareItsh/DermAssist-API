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
    protected $description = 'Process pending doctor-scheduled patient account actions (disable or delete)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $count = self::processDueActions();
        $this->info("Processed {$count} pending scheduled account actions.");
    }

    /**
     * Helper to process due account actions in real-time.
     */
    public static function processDueActions(): int
    {
        $users = User::whereNotNull('account_action')
            ->whereNotNull('account_action_scheduled_at')
            ->where('account_action_scheduled_at', '<=', now())
            ->get();

        $count = 0;
        foreach ($users as $user) {
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
                    $user->delete();
                    $count++;
                }
            } catch (\Throwable $e) {
                Log::error("Failed to process scheduled action for user {$user->id}: ".$e->getMessage());
            }
        }

        return $count;
    }
}
