<?php

namespace App\Http\Middleware;

use App\Console\Commands\ProcessScheduledAccountActions;
use Closure;
use Illuminate\Http\Request;

class CheckAccountStatus
{
    public function handle(Request $request, Closure $next)
    {
        // Process any due scheduled actions in real-time
        ProcessScheduledAccountActions::processDueActions();

        $user = $request->user();

        if ($user) {
            if ($user->account_status === 'disabled') {
                $user->tokens()->delete();

                return response()->json(['message' => 'Account has been disabled by your doctor.'], 403);
            }
        }

        return $next($request);
    }
}
