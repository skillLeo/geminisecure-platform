<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| My account to the standard (the hub's census, 12 Oct; M95, M96, the three weights)
|--------------------------------------------------------------------------
|
| `Pages/Account/Show.vue` is one page in both consoles. Every form a button opens is the console's modal, and every
| save from one goes through `submitDialog`; a role that can never turn two-step off has no button for it; a refusal is
| amber, never red; the Gemini console's messages are toasts; no em dash a person reads. The server half of the page is
| M32AccountTest and M34SignInEmailTest; the browser's half, account-page.test.mjs (a JS file, not run while the JS ban
| stands).
|
*/

function accountPageSource(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Account/Show.vue');
}

/** The template a person reads, without its HTML comments. */
function accountPageTemplate(): string
{
    $source = accountPageSource();
    $template = substr($source, (int) strpos($source, '<template>'), (int) strrpos($source, '</template>') - (int) strpos($source, '<template>'));

    return (string) preg_replace('/<!--.*?-->/s', '', $template);
}

it('opens every form a button opens in the console\'s modal, and saves each through submitDialog', function (): void {
    $source = accountPageSource();

    // The sign-in email's two steps, the password, a two-step act, the authenticator's setup, the recovery codes.
    expect(substr_count($source, '<ConsoleDialog'))->toBe(6)
        ->and(str_contains($source, 'class="ma-sub"'))->toBeFalse();

    foreach (['moving', 'proving', 'change', 'step', 'confirm'] as $form) {
        expect(str_contains($source, 'submitDialog('.$form.', \'post\''))->toBeTrue();
    }
});

it('offers no "Turn it off" to a role that must keep two-step verification on, and says why', function (): void {
    $template = accountPageTemplate();

    expect(str_contains($template, '<button v-if="!locked" type="button" class="ma-btn" title="Turn two-step verification off" @click="openStep(\'off\')">'))->toBeTrue()
        ->and(str_contains($template, '<p v-if="locked" class="ma-note ma-locked">{{ locked }}</p>'))->toBeTrue();
});

it('draws a refusal amber and no red at all, says the Gemini console\'s messages as toasts, and has no em dash to read', function (): void {
    $source = accountPageSource();

    expect(str_contains($source, 'var(--red'))->toBeFalse()
        ->and(str_contains($source, "toast(flash.success, { tone: 'success' })"))->toBeTrue()
        ->and(str_contains($source, "toast(flash.error, { tone: 'warning' })"))->toBeTrue()
        ->and(str_contains(accountPageTemplate(), '—'))->toBeFalse();
});
