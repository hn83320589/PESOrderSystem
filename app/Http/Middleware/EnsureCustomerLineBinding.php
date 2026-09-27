<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Customer\LineAuthController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 客戶的 LINE 綁定在登入後被後台解除或變更、或客戶被停用時，立即登出。
 */
class EnsureCustomerLineBinding
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = Auth::guard('customer')->user();
        $sessionSub = $request->session()->get(LineAuthController::SESSION_LINE_SUB);

        if (! $customer || ! $customer->is_active || ! $customer->line_user_id || $customer->line_user_id !== $sessionSub) {
            Auth::guard('customer')->logout();
            $request->session()->invalidate();

            return response()->json(['message' => '請重新登入'], 401);
        }

        return $next($request);
    }
}
