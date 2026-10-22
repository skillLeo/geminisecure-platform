<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Http\Controllers\Controller;
use App\Services\Platform\SupportLine;
use Illuminate\Http\JsonResponse;

/**
 * How to reach Gemini Security, as Help & support offers it (board resident-app-39): the phone line the console set —
 * or null, and then the app shows no "Call Gemini Security" at all (the user, 8 Oct 2026) — and the company's address.
 */
class SupportContactController extends Controller
{
    public function show(SupportLine $line): JsonResponse
    {
        return response()->json([
            'company' => (string) config('legal.company'),
            'phone' => $line->number(),
            'phone_display' => $line->display(),
            'email' => (string) config('legal.support_email'),
        ]);
    }
}
