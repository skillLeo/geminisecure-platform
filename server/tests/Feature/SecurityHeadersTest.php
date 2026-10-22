<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CONTENT SECURITY POLICY — M16 §5.1
|--------------------------------------------------------------------------
|
| The one security header the application owns (the rest are nginx's). This
| proves it is present on an HTML console page, that it is NOT permissive, and
| that it enforces in this environment (report-only is the production rollout
| default, promoted at go-live).
*/

beforeEach(function () {
    $this->withoutVite();
});

it('sets an enforcing, non-permissive content security policy on a web page', function () {
    // Enforcing here (testing is not production), so a template that grew an inline
    // script would fail this rather than ship.
    config(['security.csp.enforce' => true]);

    $response = $this->get('/login');

    $response->assertOk();
    $response->assertHeader('Content-Security-Policy');

    $csp = (string) $response->headers->get('Content-Security-Policy');

    // The directive that actually stops an injected <script>: self, and no unsafe-inline.
    expect($csp)->toContain("script-src 'self'")
        ->and($csp)->not->toContain("script-src 'self' 'unsafe-inline'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("frame-ancestors 'self'")
        ->and($csp)->toContain("form-action 'self'");

    // And the Permissions-Policy locks the browser powers a console page never needs.
    expect((string) $response->headers->get('Permissions-Policy'))->toContain('geolocation=()');
});

it('emits report-only when enforcement is off, so promoting it cannot break a page on launch day', function () {
    config(['security.csp.enforce' => false]);

    $response = $this->get('/login');

    $response->assertOk()
        ->assertHeader('Content-Security-Policy-Report-Only')
        ->assertHeaderMissing('Content-Security-Policy');
});
