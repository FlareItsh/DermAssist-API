<?php

namespace App\Console\Commands;

use App\Mail\VerificationReminderMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendVerificationReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-verification-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send 24h and 6h automated reminder emails to users with pending account verification';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pendingUsers = User::where('account_status', 'pending_verification')
            ->whereNotNull('verification_deadline')
            ->whereNotNull('verification_token')
            ->where('verification_deadline', '>', now())
            ->get();

        $sentCount = 0;

        foreach ($pendingUsers as $user) {
            $diffHours = (int) now()->diffInHours($user->verification_deadline, false);

            $shouldSend = false;
            $hoursText = '';

            if ($diffHours <= 6 && $user->verification_reminders_sent < 2) {
                $shouldSend = true;
                $hoursText = $diffHours > 0 ? "{$diffHours} hours" : 'Less than an hour';
            } elseif ($diffHours <= 24 && $user->verification_reminders_sent < 1) {
                $shouldSend = true;
                $hoursText = "{$diffHours} hours";
            }

            if ($shouldSend) {
                try {
                    Mail::to($user->email)->send(new VerificationReminderMail($user, $hoursText));
                    $user->increment('verification_reminders_sent');
                    $sentCount++;
                } catch (\Throwable $e) {
                    Log::error("Failed to send verification reminder to {$user->email}: ".$e->getMessage());
                }
            }
        }

        $this->info("Dispatched {$sentCount} verification reminder email(s).");

        return Command::SUCCESS;
    }
}
