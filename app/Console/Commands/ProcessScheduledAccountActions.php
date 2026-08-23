<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

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
                $user->delete();
                $count++;
            }
        }

        return $count;
    }
}
