<?php

declare(strict_types=1);

return [
    /*
     * THE CONTENT SECURITY POLICY (M16 §5.1).
     *
     * Set by App\Http\Middleware\SecurityHeaders on the web consoles and the public
     * pages (never the JSON API). It is deliberately NOT permissive:
     *
     *   - `script-src 'self'` with NO 'unsafe-inline' — the root layout carries no
     *     inline script (Vite injects external module scripts; Inertia bootstraps from
     *     a data attribute), so an injected <script> is refused. This is the directive
     *     that actually stops an XSS from running.
     *   - `style-src 'self' 'unsafe-inline'` — Vue's :style bindings and Leaflet write
     *     inline style attributes; scripts, not styles, are the injection risk.
     *   - `object-src 'none'`, `base-uri 'self'`, `frame-ancestors 'self'`,
     *     `form-action 'self'` — no plugins, no base-tag hijack, no framing, no
     *     cross-origin form post.
     *
     * The two runtime origins beyond 'self' are data-driven, so a wrong guess here does
     * not silently break the live socket or the map:
     *   - `connect_extra`: the Reverb websocket host (wss + https). Same host as the app
     *     in production, but named so a separate socket host still works.
     *   - `img_extra`: the map tile host (OpenStreetMap by default; MapTiler/Stadia is a
     *     one-line change, D-173).
     */
    'csp' => [
        /*
         * ENFORCE, OR ONLY REPORT. Enforcing in local and testing, so a template that
         * adds an inline script fails a test rather than shipping. On production it is
         * REPORT-ONLY by default (`CSP_ENFORCE=false`): the header is emitted as
         * `Content-Security-Policy-Report-Only`, the browser reports violations without
         * blocking anything, and go-live promotes it to enforce after a clean week
         * (LAUNCH_CHECKLIST → "Promote the CSP"). This is why turning it on cannot take
         * the live socket down on launch day.
         */
        'enforce' => (bool) env('CSP_ENFORCE', env('APP_ENV', 'production') !== 'production'),

        /*
         * The Reverb socket. In production REVERB_HOST is the app's own host, so 'self'
         * would usually cover it; named explicitly because a wss URL and a separate
         * socket host are exactly what 'self' does not always cover.
         */
        'connect_extra' => array_values(array_filter([
            env('REVERB_HOST') ? 'wss://'.env('REVERB_HOST') : null,
            env('REVERB_HOST') ? 'https://'.env('REVERB_HOST') : null,
        ])),

        // The map tile host. Parsed to an origin from the tile URL template.
        'img_extra' => array_values(array_filter([
            env('VITE_MAP_TILES_URL')
                ? (parse_url((string) env('VITE_MAP_TILES_URL'), PHP_URL_SCHEME).'://'.parse_url((string) env('VITE_MAP_TILES_URL'), PHP_URL_HOST))
                : 'https://tile.openstreetmap.org',
        ])),
    ],
];
