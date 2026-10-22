<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Gemini\PlatformNotifications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The notification centre behind the dashboard bell (12 §2, item 41). No board
 * draws the centre itself. See `PlatformNotifications`.
 */
class NotificationController extends Controller
{
    public function index(Request $request, PlatformNotifications $notifications): Response
    {
        return inertia('Gemini/Notifications/Index', $notifications->attention($request->user()));
    }

    /** Mark what the viewer has seen — the keys they were shown, and nothing else. */
    public function read(Request $request, PlatformNotifications $notifications): RedirectResponse
    {
        $data = $request->validate([
            'keys' => ['required', 'array', 'max:200'],
            'keys.*' => ['string', 'max:64'],
        ]);

        $notifications->markRead($request->user(), $data['keys']);

        return back();
    }
}
