<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A work order's buttons in the order the work goes, the next step the one solid button (Hassam, 12 Oct, live)
|--------------------------------------------------------------------------
|
| /facilities/maintenance/1054: five buttons in no order, Mark completed solid and first. His words: "make them better
| sorting, then which one is supposed to be clicked first so it is easy for the user to understand. Don't make any
| restriction." So: Acknowledge, Assign vendor, Start work, Mark completed, top to bottom; the next step for the ticket
| the one solid button under "Next step"; nothing greyed or refused that was pressable; Message resident apart, below.
| Read as text (a unit test boots no application; the JS runner is banned).
|
*/

it('draws the steps in the workflow\'s order, the next one solid, and Message resident apart below them', function (): void {
    $vue = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Facilities/Ticket.vue'));
    $stack = substr($vue, (int) strpos($vue, '<div v-if="canUpdate" class="action-stack">'));
    $stack = substr($stack, 0, (int) strpos($stack, '<p v-if="!ticket.is_closed && assignBlockedBy !== null" class="tk-why">'));

    $at = static fn (string $needle): int|false => strpos($stack, $needle);
    $order = [$at("@click=\"stamp('acknowledge')\""), $at("@click=\"openPanel('assign')\""), $at("@click=\"stamp('start')\""), $at("@click=\"openPanel('resolve')\""), $at("@click=\"openPanel('message')\"")];

    expect(in_array(false, $order, true))->toBeFalse('a step is missing from the stack')
        ->and($order)->toBe(array_values(collect($order)->sort()->all()), 'the steps are not in the order the work goes');

    // The next step is the one solid button, each step's emphasis following it; nothing else is primary on an open job.
    foreach (['acknowledge', 'assign', 'start', 'resolve'] as $step) {
        expect(str_contains($stack, ":class=\"nextStep === '{$step}' ? 'primary' : 'outline'\""))->toBeTrue('the '.$step.' step does not follow the next step')
            ->and(str_contains($stack, "<span v-if=\"nextStep === '{$step}'\" class=\"tk-next\">Next step</span>"))->toBeTrue('no "Next step" cue for '.$step);
    }

    expect(substr_count($stack, 'class="stack-btn primary"'))->toBe(1, 'only Reopen, on a closed ticket, is always solid')
        ->and(str_contains($stack, 'class="stack-btn outline tk-apart"'))->toBeTrue('Message resident is not set apart');

    // The rule: seen, then given to a supplier, then started, then completed.
    $rule = substr($vue, (int) strpos($vue, 'const nextStep = computed('), 520);
    expect(strpos($rule, "return 'acknowledge'") < strpos($rule, "return 'assign'"))->toBeTrue()
        ->and(str_contains($rule, "return props.ticket.can_start ? 'start' : 'resolve'"))->toBeTrue();
});
