<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SITE TIME OR MY TIME (M19A §7). The switch in the Gemini console's header, remembered on the user.
 *
 *   site   every row on its own estate's clock, tagged "9:38 AM JA" — the default
 *   mine   every row on the viewer's own clock, tagged with theirs; the zone is what their browser says it is
 */
class TimeViewController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'view' => ['required', 'string', 'in:site,mine'],
            'zone' => ['required_if:view,mine', 'nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ]);

        $request->user()->forceFill([
            'console_time_view' => $data['view'],
            'console_zone' => $data['view'] === 'mine' ? $data['zone'] : $request->user()->console_zone,
        ])->save();

        return back();
    }
}
