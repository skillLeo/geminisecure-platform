<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Services\Estate\EstateStructure;
use App\Services\Estate\Residents;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;

/**
 * Estate structure — board screen community-admin-03.
 *
 * EVERY COUNT ON THIS SCREEN IS DERIVED FROM `units`. The unit total, the
 * occupied count, the vacancies and the occupancy bar are one GROUP BY over the
 * estate's own addresses; nothing on a phase row carries them. A stored unit
 * count would be free to say 92 about a phase somebody had added a lot to, and
 * this is precisely the screen a committee checks that arithmetic on.
 *
 * BOTH WRITES ARE BUILT (12 §2, Wave 1), AND BOTH ARE ALL-OR-NOTHING. A phase
 * is added with its blocks and its lot range decided together and every lot
 * created in one transaction; a unit list is previewed, validated whole, and
 * committed whole or not at all — a bad file is rejected whole. The preview
 * is held in the session under a token, and the commit re-validates against
 * the estate as it is at that moment rather than trusting the preview.
 */
class EstateStructureController extends Controller
{
    /** The phase cards — board community-admin-03. */
    public function index(Request $request, Residents $residents): Response
    {
        $token = (string) $request->query('import', '');
        $preview = $token === '' ? null : $request->session()->get('units-import.'.$token);

        // The contract, where the estate stands, and its lots (D-176, M32 F2, F6): one read of the units for all three.
        $head = app(EstateStructure::class)->head(
            blocks: max(1, (int) $request->integer('blocks', 1)),
            from: $request->filled('from') ? (int) $request->integer('from') : null,
            to: $request->filled('to') ? (int) $request->integer('to') : null,
        );

        return inertia('Estate/Structure/Index', [
            'estate' => ['name' => (string) tenant()->name],
            ...$residents->structureBoard(),

            /*
             * Both writes are `create` on estate structure, which the Treasurer
             * and the Admin Assistant do not hold at all — they cannot reach
             * this screen either, so the flag is about the President and the
             * Vice President, who may read the estate's layout and not change
             * it.
             */
            'canCreate' => $request->user()->can('estate.estate_structure.create'),

            // The contract's units and the estate's (D-176): lots are added up to the one, and the screen says where it stands.
            'contract' => $head['contract'],

            // M32 F2: the three numbers and the one sentence at the top of the screen, and whether a phase may be added at all.
            'standing' => $head['standing'],

            /*
             * M32 F6: which lot numbers the estate has and which are free, before a number is typed — and, as the form
             * types them (the page asks again with `blocks`, `from` and `to`), the next free block and one line about
             * the range typed.
             */
            'lots' => $head['lots'],
            'blockedReason' => 'Changing the estate\'s layout needs Estate structure create access.',

            // The previewed import, if the reader is between the two presses.
            'preview' => $preview === null ? null : [
                'token' => $token,
                'rows' => array_slice($preview['rows'], 0, 200),
                'row_count' => count($preview['rows']),
                'errors' => $preview['errors'],
                'new_phases' => $preview['new_phases'],
                // M25 §2.3: every phase's lots, said before Import — the table above shows only the first 200 rows.
                'by_phase' => EstateStructure::summarise($preview['rows'], $preview['new_phases']),
                'valid' => $preview['valid'],
                'file' => $preview['file'],
            ],
        ]);
    }

    /**
     * Add a phase and the lots in it.
     *
     * The name is held to what a UNIT can carry, not to what the phase row can
     * (B046): every lot in the phase is filed under it, in a 32-character column,
     * and a longer name passed here and then failed inside the transaction.
     */
    public function addPhase(Request $request, EstateStructure $structure): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.EstateStructure::PHASE_NAME_MAX],
            'block_count' => ['required', 'integer', 'min:0', 'max:200'],
            'lot_from' => ['nullable', 'integer', 'min:1'],
            'lot_to' => ['nullable', 'integer', 'min:1'],
            'street' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $result = $structure->addPhase(
                name: $data['name'],
                blockCount: (int) $data['block_count'],
                lotFrom: isset($data['lot_from']) ? (int) $data['lot_from'] : null,
                lotTo: isset($data['lot_to']) ? (int) $data['lot_to'] : null,
                street: $data['street'] ?? null,
                by: $request->user(),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['name' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/estate'))
            ->with('success', sprintf(
                '%s added%s.',
                $result['phase']->name,
                $result['units'] === 0 ? ' with no lots yet' : ' with '.$result['units'].' lot'.($result['units'] === 1 ? '' : 's'),
            ));
    }

    /** Step one of the import: parse, validate every row, show the preview. Nothing is written. */
    public function previewImport(Request $request, EstateStructure $structure): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:csv,txt'],
        ]);

        $file = $request->file('file');
        $parsed = $structure->parseUnits((string) $file->get());
        $token = Str::random(20);

        $request->session()->put('units-import.'.$token, [
            ...$parsed,
            'file' => $file->getClientOriginalName(),
        ]);

        return redirect()->to($this->path('/estate?import='.$token));
    }

    /** Step two: commit the previewed list — all of it, or none of it. */
    public function commitImport(Request $request, EstateStructure $structure): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:20'],
        ]);

        $preview = $request->session()->get('units-import.'.$data['token']);

        if ($preview === null) {
            return back()->withErrors(['import' => 'That preview has expired. Upload the file again.']);
        }

        if (! $preview['valid']) {
            return back()->withErrors(['import' => 'Some rows have problems. Fix the file and preview it again.']);
        }

        try {
            $result = $structure->importUnits($preview['rows'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['import' => $refused->getMessage()]);
        }

        $request->session()->forget('units-import.'.$data['token']);

        return redirect()
            ->to($this->path('/estate'))
            ->with('success', sprintf(
                '%d unit%s imported%s.',
                $result['units'],
                $result['units'] === 1 ? '' : 's',
                $result['phases'] === 0 ? '' : ', and '.$result['phases'].' new phase'.($result['phases'] === 1 ? '' : 's').' created',
            ));
    }

    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
