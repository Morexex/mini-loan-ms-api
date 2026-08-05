<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySmsForwarderSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('daraja.sms_forwarder_secret');

        if ($expected === '') {
            return response()->json([
                'message' => 'SMS forwarder webhook is not configured.',
            ], 503);
        }

        $provided = (string) ($request->header('X-Sms-Forwarder-Secret')
            ?? $request->bearerToken()
            ?? '');

        if (! hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        return $next($request);
    }
}
