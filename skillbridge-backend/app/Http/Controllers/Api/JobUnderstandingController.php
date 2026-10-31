<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\JobUnderstandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class JobUnderstandingController extends Controller
{
    public function parse(Request $request, JobUnderstandingService $parser): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'min:10', 'max:10000'],
        ]);

        try {
            return response()->json([
                'requirements' => $parser->parse(trim($validated['text'])),
                'source' => 'ai',
                'fallback_available' => true,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json([
                'message' => 'AI parsing is temporarily unavailable. Continue with the normal job form.',
                'code' => 'AI_PARSER_UNAVAILABLE',
                'fallback_available' => true,
            ], 503);
        }
    }
}
