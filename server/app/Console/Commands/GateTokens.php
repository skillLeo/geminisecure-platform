<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * No colour is written as a hex literal outside tokens.css and a documented
 * allowlist.
 *
 * WHY THIS EXISTS. `tokens.css` has said since Phase 0: "A hex literal written
 * inline in a component is a defect. Reference these tokens instead." It even
 * says of `--success-100` that it was "tokenised here so no component carries
 * the literal" — and ten components carried the literal anyway, found by looking
 * rather than by any check. The client's instruction: fix them, and "add a gate
 * that fails the build on any hex literal outside tokens.css and a documented
 * allowlist — otherwise this recurs" (D-085).
 *
 * A LITERAL THAT MATCHES A TOKEN IS STILL A DEFECT, and it is the dangerous
 * kind. It looks identical today. Change the token and every component carrying
 * the literal keeps the old colour, silently — which is the one thing a token
 * system exists to prevent.
 *
 * WHAT IS ALLOWED, AND EVERY ENTRY SAYS WHY. Each one weakens the gate, so each
 * is argued in its own words below. The lifted wireframe stylesheets are allowed
 * wholesale because they ARE the approved design, generated verbatim by
 * `tests/Fidelity/lift-stylesheets.mjs` and marked "do not edit by hand"; the
 * rest are single literals in single files, ruled on or genuinely without a
 * token.
 *
 * Like `gate:assumptions`, this proves a habit rather than a behaviour: it reads
 * files and asserts that a rule written in one of them is kept in the others.
 */
class GateTokens extends Command
{
    protected $signature = 'gate:tokens';

    protected $description = 'Fail when a colour is written as a hex literal outside tokens.css and its allowlist';

    /** Where colours can be written. */
    private const SCAN = [
        'resources/js' => ['*.vue', '*.js'],
        'resources/css' => ['*.css'],
        'resources/views' => ['*.blade.php'],
        'app' => ['*.php'],
    ];

    /**
     * Whole paths allowed to carry literals, with the reason.
     *
     * @var array<string, string>
     */
    private const ALLOWED_PATHS = [
        'resources/css/tokens.css' => 'The token source of truth. Literals here are the definitions.',
        'resources/css/wireframe/' => 'The approved wireframes\' own stylesheets, lifted verbatim — the design input, generated, never edited by hand.',
        'resources/css/scoped/' => 'The same stylesheets scoped per board by tests/Fidelity/lift-stylesheets.mjs — generated, never edited by hand.',
        'app/Console/Commands/GateTokens.php' => 'This gate. Its allowlist names the literals it permits, so it would otherwise fail on its own list.',

        /*
         * THE PDF TEMPLATES, AND THE REASON IS THE RENDERER'S. These are not
         * screens: dompdf resolves no CSS custom properties, so `var(--navy-900)`
         * in a document stylesheet renders as no colour at all — black text on a
         * statement where the palette was meant to be, and nobody notices until a
         * resident holds the paper. The literals here are the same values the
         * tokens carry, written out because the renderer cannot read a token.
         *
         * A token that changes must be changed here too, and that is the cost of
         * the exemption rather than an oversight: it is recorded so the next
         * person changing a token knows this is the second place.
         */
        'resources/views/documents/' => 'The issued-document templates. dompdf resolves no CSS custom properties, so a var() here renders as no colour — these literals are the tokens\' own values, written out because the PDF renderer cannot read a token.',
    ];

    /**
     * Single literals allowed in single files, with the reason. Lowercase.
     *
     * @var array<string, array{literals: list<string>, reason: string}>
     */
    private const ALLOWED_LITERALS = [
        'resources/js/Layouts/EstateConsole.vue' => [
            'literals' => ['#0a2d52'],
            'reason' => 'The estate sidebar gradient ends in #0A2D52 on all 40 approved boards and no token holds it — '
                .'reproduced faithfully and recorded in DESIGN_SYSTEM_FINDINGS. Exempt by the client\'s ruling.',
        ],
        'resources/js/Pages/Estate/Settings/Profile.vue' => [
            'literals' => ['#ffffff', '#ffb627'],
            'reason' => 'The brand mark\'s own SVG fills on the logo preview. Exempt by the client\'s ruling.',
        ],
        'resources/js/brand.js' => [
            'literals' => ['#3b2166', '#1974d2', '#ffffff', '#ffb627'],
            'reason' => 'The brand mark\'s colourway definitions, drawn as SVG fills. Exempt by the client\'s ruling.',
        ],
        /*
         * THE STORE-REQUIRED PUBLIC PAGES (M5 §4), and the reason is how they must load.
         * /legal/privacy, /legal/terms, /support and /account/delete are plain server-
         * rendered HTML with no build step and no script, so a store's checker and a slow
         * phone both get them whole — which means no Vite bundle and therefore no
         * tokens.css. The layout carries the tokens' own values ONCE, in one `:root`
         * block, and every rule beneath reads them through var(). `--amber-800` has no
         * token: it is a darker amber so the DRAFT banner's text clears 4.5:1 on
         * `--amber-100`, which `--amber-700` does not. A token that changes must be
         * changed here too; that is the cost of the exemption, written down.
         */
        'resources/views/public/layout.blade.php' => [
            'literals' => [
                '#ffffff', '#0b1f33', '#123b6b', '#1974d2', '#e7f0fb', '#ffb627',
                '#fff3dc', '#8f5e06', '#3e5972', '#f4f8fc', '#c9def5', '#b91c1c',
            ],
            'reason' => 'The public store pages load no bundle and so no tokens.css; the layout defines the tokens\' own '
                .'values once in :root and every rule reads them through var().',
        ],
        'resources/js/Pages/ErrorPage.vue' => [
            'literals' => ['#2d1b69', '#0b0a1f'],
            'reason' => 'The approved boards\' dark gradient, used on the error page. Both are in the wireframes and '
                .'neither is a token; tokenising them is a design-system change, not a gate fix.',
        ],
    ];

    public function handle(): int
    {
        $violations = [];
        $allowedHits = 0;

        foreach ($this->files() as $relative => $absolute) {
            if ($this->pathAllowed($relative)) {
                continue;
            }

            $lines = preg_split('/\R/', (string) file_get_contents($absolute)) ?: [];

            foreach ($lines as $index => $line) {
                if (preg_match_all('/(?<![&\w])#(?:[0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/', $line, $matches) < 1) {
                    continue;
                }

                foreach ($matches[0] as $literal) {
                    if ($this->literalAllowed($relative, strtolower($literal))) {
                        $allowedHits++;

                        continue;
                    }

                    $violations[] = sprintf('%s:%d  %s', $relative, $index + 1, trim($line));
                }
            }
        }

        foreach (self::ALLOWED_PATHS as $path => $reason) {
            $this->line(" <fg=yellow>ALLOW</> {$path} — {$reason}");
        }

        foreach (self::ALLOWED_LITERALS as $path => $entry) {
            $this->line(sprintf(' <fg=yellow>ALLOW</> %s %s — %s', $path, implode(' ', $entry['literals']), $entry['reason']));
        }

        $this->newLine();

        if ($violations !== []) {
            foreach ($violations as $violation) {
                $this->line(" <fg=red>FAIL</> {$violation}");
            }

            $this->newLine();
            $this->error(sprintf(
                'GATE FAILED - %d hex literal%s outside tokens.css. Reference the token instead — a literal that '
                .'matches a token today is the one that silently keeps the old colour when the token changes. '
                .'If a colour genuinely has no token, it belongs in ALLOWED_LITERALS with its reason.',
                count($violations),
                count($violations) === 1 ? '' : 's',
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'GATE PASSED - no hex literal outside tokens.css and its allowlist (%d allowed literal%s, each with its reason).',
            $allowedHits,
            $allowedHits === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }

    /**
     * Every file in scope, keyed by its path relative to the project root.
     *
     * @return array<string, string>
     */
    private function files(): array
    {
        $files = [];

        foreach (self::SCAN as $dir => $patterns) {
            $absolute = base_path($dir);

            if (! is_dir($absolute)) {
                continue;
            }

            $finder = (new Finder)->files()->in($absolute)->name($patterns);

            foreach ($finder as $file) {
                $path = (string) $file->getRealPath();
                $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));

                $files[$relative] = $path;
            }
        }

        ksort($files);

        return $files;
    }

    private function pathAllowed(string $relative): bool
    {
        foreach (array_keys(self::ALLOWED_PATHS) as $allowed) {
            if ($relative === $allowed || (str_ends_with($allowed, '/') && str_starts_with($relative, $allowed))) {
                return true;
            }
        }

        return false;
    }

    private function literalAllowed(string $relative, string $literal): bool
    {
        return in_array($literal, self::ALLOWED_LITERALS[$relative]['literals'] ?? [], true);
    }
}
