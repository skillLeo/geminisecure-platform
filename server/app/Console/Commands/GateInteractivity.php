<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Asserts that no element looks interactive and does nothing.
 *
 * The consoles were first built as faithful STILL IMAGES of the wireframes:
 * correct pixels, and buttons rendered as <div>. A board can afford that. An
 * application cannot — a user who clicks and gets silence has been lied to by
 * the interface.
 *
 * So this counts what is really there, and fails on the specific shapes that
 * silence takes:
 *
 *   a <div> or <span> carrying @click            — not focusable, not tabbable,
 *                                                  invisible to a screen reader
 *   a control class on a non-interactive element — looks like a button, is not
 *   disabled with no title                       — inert with no explanation
 *   a <form> with no submit handler              — swallows the submission
 *
 * The last one matters most for search: a field that accepts a query and does
 * nothing with it is worse than one that says it is not ready.
 *
 * A control whose backend genuinely does not exist yet is legitimate, but only
 * when it is VISIBLY inert: the disabled attribute plus a title saying why.
 * That is a deliberate, readable choice; a live-looking dead control is not.
 */
class GateInteractivity extends Command
{
    protected $signature = 'gate:interactivity
        {--path=resources/js/Pages : Directory of Vue pages to audit.}
        {--table : Print the per-screen counts as well as the defects.}';

    protected $description = 'Fail if any element looks interactive and does nothing';

    /**
     * Class names the boards use for things that are clearly controls.
     *
     * Derived from the lifted board stylesheets. An element wearing one of
     * these is drawn as a button, so it must BE one.
     */
    private const CONTROL_CLASSES = [
        'btn', 'btn-primary', 'btn-outline', 'btn-sm', 'btn-danger',
        'top-icon-btn', 'nav-item', 'tab', 'chip-filter', 'pager-btn',
        'row-action', 'action-btn',
    ];

    public function handle(): int
    {
        $root = base_path((string) $this->option('path'));

        if (! File::isDirectory($root)) {
            $this->error("No such directory: {$root}");

            return self::FAILURE;
        }

        $rows = [];
        $defects = [];

        /** @var SplFileInfo $file */
        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'vue') {
                continue;
            }

            $source = File::get($file->getPathname());
            $name = str_replace('\\', '/', $file->getRelativePathname());

            $rows[] = [
                'screen' => substr($name, 0, -4),
                'button' => $this->count($source, '/<button\b/i'),
                'link' => $this->count($source, '/<Link\b/i'),
                'input' => $this->count($source, '/<(input|select|textarea)\b/i'),
                'form' => $this->count($source, '/<form\b/i'),
                'inert' => $this->countInert($source),
            ];

            foreach ($this->defectsIn($source, $name) as $defect) {
                $defects[] = $defect;
            }
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['screen'], $b['screen']));

        if ($this->option('table')) {
            $this->renderTable($rows);
        }

        if ($defects === []) {
            $this->newLine();
            $this->info(sprintf(
                'GATE PASSED - %d page(s) audited, no element looks interactive and does nothing.',
                count($rows)
            ));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error(sprintf('GATE FAILED - %d defect(s):', count($defects)));
        $this->newLine();

        foreach ($defects as $defect) {
            $this->line("  <fg=red>{$defect['kind']}</>  {$defect['file']}");
            $this->line("      {$defect['detail']}");
        }

        return self::FAILURE;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderTable(array $rows): void
    {
        $this->table(
            ['Screen', 'button', 'Link', 'input', 'form', 'inert (with reason)'],
            array_map(
                fn (array $r): array => [
                    $r['screen'],
                    $r['button'],
                    $r['link'],
                    $r['input'],
                    $r['form'],
                    $r['inert'],
                ],
                $rows
            )
        );
    }

    private function count(string $source, string $pattern): int
    {
        return preg_match_all($pattern, $source) ?: 0;
    }

    /**
     * Controls that are deliberately inert: disabled AND carrying a reason.
     *
     * Counted rather than flagged, because this is the ONE legitimate way to
     * render a control whose backend does not exist yet.
     */
    private function countInert(string $source): int
    {
        $n = 0;

        foreach ($this->tags($source) as $tag) {
            if ($this->isPermanentlyDisabled($tag) && preg_match('/\btitle="/i', $tag)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @return list<array{kind: string, file: string, detail: string}>
     */
    private function defectsIn(string $source, string $file): array
    {
        $found = [];

        foreach ($this->tags($source) as $tag) {
            $element = strtolower((string) preg_replace('/^<\s*([A-Za-z0-9-]+).*$/s', '$1', $tag));

            /*
             * @click on something that is not focusable.
             *
             * A keyboard user cannot reach it, a screen reader does not
             * announce it, and Enter does not fire it. Adding tabindex and a
             * role reproduces a button badly; using a button reproduces it
             * exactly.
             */
            if (in_array($element, ['div', 'span', 'li', 'td', 'p'], true)
                && preg_match('/@click|v-on:click/i', $tag)
                && ! preg_match('/\.stop\b/i', $tag)) {
                $found[] = [
                    'kind' => 'click-on-non-control',
                    'file' => $file,
                    'detail' => 'a <'.$element.'> carries @click. Use <button> or <Link>: '.$this->snippet($tag),
                ];
            }

            // Drawn as a control, rendered as scenery.
            if (in_array($element, ['div', 'span'], true) && $this->wearsControlClass($tag)) {
                $found[] = [
                    'kind' => 'control-class-on-div',
                    'file' => $file,
                    'detail' => 'a <'.$element.'> wears a control class. Use <button> or <Link>: '.$this->snippet($tag),
                ];
            }

            // Permanently inert with no explanation.
            if ($this->isPermanentlyDisabled($tag) && ! preg_match('/\btitle="/i', $tag)) {
                $found[] = [
                    'kind' => 'disabled-without-reason',
                    'file' => $file,
                    'detail' => 'always disabled, with no title saying why: '.$this->snippet($tag),
                ];
            }
        }

        // A form that swallows its submission.
        if (preg_match('/<form\b/i', $source)
            && ! preg_match('/@submit|v-on:submit|useForm/i', $source)) {
            $found[] = [
                'kind' => 'form-without-handler',
                'file' => $file,
                'detail' => 'a <form> with no @submit and no useForm. It will reload the page and lose the input.',
            ];
        }

        return $found;
    }

    /**
     * Opening tags in the <template> block only.
     *
     * The <script> block is skipped: a string in JavaScript that happens to
     * contain "<div @click" is not markup, and flagging it trains people to
     * ignore this gate.
     *
     * @return list<string>
     */
    private function tags(string $source): array
    {
        if (! preg_match('/<template>(.*)<\/template>/s', $source, $m)) {
            return [];
        }

        preg_match_all('/<[A-Za-z][^>]*>/s', $m[1], $tags);

        return $tags[0];
    }

    /**
     * Disabled ALWAYS, as opposed to disabled right now.
     *
     * `:disabled="form.processing"` is a busy state: the control works, it is
     * simply mid-flight, and demanding a title for it would train people to
     * paper every submit button with an explanation nobody needs.
     *
     * A bare `disabled`, or one bound to a literal true, means something else
     * entirely — this control does not work and is not going to during this
     * release. THAT is the case which owes the reader a reason.
     */
    private function isPermanentlyDisabled(string $tag): bool
    {
        if (preg_match('/(?::|v-bind:)disabled\s*=\s*"([^"]*)"/i', $tag, $m)) {
            return trim($m[1]) === 'true';
        }

        return (bool) preg_match('/\sdisabled(?=[\s>])/i', $tag);
    }

    private function wearsControlClass(string $tag): bool
    {
        if (! preg_match('/\bclass="([^"]*)"/i', $tag, $m)) {
            return false;
        }

        $classes = preg_split('/\s+/', $m[1]) ?: [];

        return array_intersect($classes, self::CONTROL_CLASSES) !== [];
    }

    private function snippet(string $tag): string
    {
        $flat = (string) preg_replace('/\s+/', ' ', $tag);

        return strlen($flat) > 110 ? substr($flat, 0, 107).'...' : $flat;
    }
}
