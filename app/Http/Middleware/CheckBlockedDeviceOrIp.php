<?php

namespace App\Http\Middleware;

use App\Models\BlockedDevice;
use App\Models\BlockedIp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBlockedDeviceOrIp
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $deviceId = $request->header('X-Device-Id') ?? $request->cookie('da_device_id') ?? $request->input('device_token');

        if ($deviceId) {
            $isDeviceBlocked = BlockedDevice::where('device_id', $deviceId)->exists();
            if ($isDeviceBlocked) {
                return response()->json([
                    'message' => 'Access denied: This device has been restricted due to security violations.',
                    'is_device_blocked' => true,
                ], 403);
            }
        }

        $clientIp = $request->ip();
        if ($clientIp) {
            $isIpBlocked = BlockedIp::where('ip_address', $clientIp)
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->exists();

            if ($isIpBlocked) {
                return response()->json([
                    'message' => 'Access denied: Your network has been restricted due to suspicious activity.',
                    'is_ip_blocked' => true,
                ], 403);
            }
        }

        return $next($request);
    }
}
