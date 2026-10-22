<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE CONTENT HEADERS THE APPLICATION OWNS (M16 §5.1).
 *
 * The transport headers — HSTS, X-Frame-Options, X-Content-Type-Options,
 * Referrer-Policy — are set by nginx (`deploy/20-https.sh`), where the certificate
 * and the server names already live. What nginx cannot easily build from the app's
 * own config — the Content-Security-Policy and Permissions-Policy — is set HERE,
 * in git beside the code that decides the allowed origins, so the two are changed
 * together and a header is never left promising a source the app no longer uses.
 *
 * WEB ONLY. The JSON API returns data an app parses, not a document a browser
 * renders, so a CSP on it protects nothing and a Permissions-Policy on it means
 * nothing; this runs in the `web` group alone.
 *
 * See `config/security.php` for the policy and why it is report-only on production
 * until go-live promotes it.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Redirects and downloads carry no document to police; a CSP on them is noise.
        $contentType = (string) $response->headers->get('Content-Type', '');

        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $policy = $this->contentSecurityPolicy();

        $header = (bool) config('security.csp.enforce', false)
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';

        $response->headers->set($header, $policy);

        // No geolocation, camera, microphone, payment or USB from a console page. The
        // Guard/Resident phone apps use those, but they are native apps, not this site.
        $response->headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=(), usb=()');

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        /** @var list<string> $connectExtra */
        $connectExtra = (array) config('security.csp.connect_extra', []);
        /** @var list<string> $imgExtra */
        $imgExtra = (array) config('security.csp.img_extra', []);

        $connect = trim("'self' ".implode(' ', $connectExtra));
        $img = trim("'self' data: blob: ".implode(' ', $imgExtra));

        $directives = [
            "default-src 'self'",
            // No inline script (the root layout has none); this is the directive that
            // stops an injected <script> from running.
            "script-src 'self'",
            // Vue :style bindings and Leaflet write inline style attributes.
            "style-src 'self' 'unsafe-inline'",
            'img-src '.$img,
            "font-src 'self'",
            'connect-src '.$connect,
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "frame-src 'none'",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
        ];

        return implode('; ', $directives);
    }
}
