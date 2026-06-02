<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPythonApiToken
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = config('services.python_api_token') ?: env('PYTHON_API_TOKEN');
        $incomingToken = $request->header('X-PYTHON-API-TOKEN');

        if (empty($expectedToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Python API token is not configured.',
            ], 500);
        }

        if (empty($incomingToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Missing Python API token.',
            ], 401);
        }

        if (! hash_equals($expectedToken, $incomingToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Python API token.',
            ], 403);
        }

        return $next($request);
    }
}
