<?php

use App\Api\ApiError;
use App\Http\Middleware\Api\DeviceTime;
use App\Http\Middleware\Api\EnforceAccessLevel;
use App\Http\Middleware\Api\Idempotent;
use App\Http\Middleware\Api\ResolveDevice;
use App\Http\Middleware\BindSessionToItsDoor;
use App\Http\Middleware\CaptureLiveHeads;
use App\Http\Middleware\EndSessionOfInactiveAccount;
use App\Http\Middleware\EnforceQueryBudget;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\KeepAccountSession;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * THE QUERY BUDGET (M11 A2), OUTERMOST, so the count includes resolving the estate,
         * the session, the signed-in user and every prop an Inertia page shares — not only
         * the controller. Counts in local and testing and nowhere else; see config/perf.php.
         */
        $middleware->prepend(EnforceQueryBudget::class);

        $middleware->web(append: [
            // A session is good only on the door that made it (M59): ranked below, straight after the session starts.
            BindSessionToItsDoor::class,
            // Before Inertia, which shares the signed-in user with every page (B068).
            EndSessionOfInactiveAccount::class,
            // A session signed out from My account ends here, and where an account is signed in is kept (M32 F9).
            KeepAccountSession::class,
            // The live heads a page's data is at least as new as (M11 Part C, DESIGN §5.2): ranked after
            // the estate opens (tenancy is highest priority) and before any binding, below.
            CaptureLiveHeads::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // The Content-Security-Policy and Permissions-Policy (M16 §5.1). Last, so it
            // sets its headers on the fully-built response, HTML pages only.
            SecurityHeaders::class,
        ]);

        /*
         * A SUSPENDED ACCOUNT IS SIGNED OUT BEFORE `auth` LOOKS (B068). Ranked ahead of
         * `auth` so a route's own `auth` sees the session already ended and answers as
         * it does for any guest: the sign-in page, or 401 to an XHR. Left unranked, the
         * framework would move `auth` in front of it and wave the account through.
         */
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EndSessionOfInactiveAccount::class);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: KeepAccountSession::class);

        /*
         * A SESSION MADE ON ANOTHER DOOR IS REFUSED BEFORE ANYTHING READS IT (M59). Ranked straight after the session
         * starts, ahead of the shared errors, the CSRF check and everything above that asks who is signed in, so a
         * session copied from one estate's host to another's, or to the Gemini console's, is a stranger's from here on.
         */
        $middleware->prependToPriorityList(before: ShareErrorsFromSession::class, prepend: BindSessionToItsDoor::class);

        /*
         * Sanctum ability gates for /api/v1.
         *
         * A device token is not a general key to the API. A guard's handset
         * may raise an alert; it may not read payroll. Laravel does not
         * register these aliases itself, so without this line a route asking
         * for 'ability:...' fails to resolve the middleware and — depending on
         * configuration — either errors or passes the request straight
         * through, which is the worse of the two.
         */
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,

            // The mobile API's own three (13 D1) — see each class.
            'device' => ResolveDevice::class,
            'device.time' => DeviceTime::class,
            'idempotent' => Idempotent::class,

            // A household member's access level — Member, Dependent, Helper — on every Resident App route.
            'level' => EnforceAccessLevel::class,
        ]);

        /*
         * THE ABILITY AND THE APP ARE CHECKED BEFORE ANY ROUTE BINDING (13 D2).
         * Without this, `SubstituteBindings` — which the framework ranks ahead of
         * route middleware it does not know — resolves `{shift}` first, and a
         * resident's token learns which shift ids exist from a 404 against a 403.
         * A token that may not reach an endpoint learns nothing about its records.
         */
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: CheckForAnyAbility::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveDevice::class);

        // And the access level after the device (it reads the account `device` resolved), still before any binding.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnforceAccessLevel::class);

        /*
         * THE LIVE HEADS, READ BEFORE THE CONTROLLER READS ANYTHING (M11 Part C, DESIGN §5.2). On
         * every API request, after `device` has opened the handset's estate so that estate's log is
         * read too, and still ahead of any binding: every answer then says, in `X-Live-Head`, a point
         * in each log that its own data is at least as new as.
         */
        $middleware->api(append: [CaptureLiveHeads::class]);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: CaptureLiveHeads::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * WHAT A REFUSED FORM MUST NOT LEAVE BEHIND.
         *
         * A failed validation redirects BACK WITH THE OLD INPUT, and the old input is written
         * to the session — which here is the `sessions` table, a `payload` column in plain
         * text. The framework's own list covers `password`; it does not know about a
         * supervisor's clock-in override PIN (M4 §3.4), so every mistyped confirmation on
         * `/account/override-pin` wrote the six digits the supervisor was about to keep into
         * that table. Everywhere else on this server the PIN exists only as a hash.
         *
         * `token` for the same reason (M10 §1): the SMS provider's auth token is typed into
         * Platform settings → SMS, and a refused save — a sender ID one character too long —
         * would otherwise write it into that table in the clear, when everywhere else it is
         * encrypted at rest. A password-reset token is the same kind of thing and is better
         * off out of the session too; no screen reads either back from old input.
         *
         * Added to, never replaced: the framework's defaults are merged with these.
         *
         * `verification_code` for the same reason (M10 §1.4): the contact gate stores its
         * one-time codes only as a hash, and a refused code — expired, or right but typed a
         * second too late — would otherwise sit in the session payload as typed.
         */
        $exceptions->dontFlash(['pin', 'pin_confirmation', 'token', 'verification_code']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * THE MOBILE API'S ONE ERROR SHAPE (13 D1):
         *
         *   { "error": { "code": "stable_snake_case", "message": "A sentence." } }
         *
         * A named refusal renders as itself. The framework's own refusals on
         * /api/* are given codes too, so an app branches on `error.code` and never
         * on a status code alone. Validation keeps Laravel's `errors` map beside
         * the envelope, because per-field messages are what a form needs.
         */
        $exceptions->render(fn (ApiError $error) => response()->json($error->body(), $error->status, $error->headers));

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $exception instanceof ValidationException => response()->json([
                    'error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()],
                    'message' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                ], $exception->status),
                $exception instanceof AuthenticationException => response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'This request needs a handset token.']], 401),
                // Sanctum's MissingAbilityException reaches here already converted.
                $exception instanceof AccessDeniedHttpException && $exception->getPrevious() instanceof MissingAbilityException,
                $exception instanceof MissingAbilityException => response()->json(['error' => ['code' => 'missing_ability', 'message' => 'This handset\'s token does not carry the ability this endpoint needs.']], 403),
                $exception instanceof AccessDeniedHttpException => response()->json(['error' => ['code' => 'forbidden', 'message' => $exception->getMessage()]], 403),
                $exception instanceof ThrottleRequestsException => response()->json(['error' => ['code' => 'rate_limited', 'message' => 'Too many requests from this handset. Wait and retry with the same Idempotency-Key.']], 429, $exception->getHeaders()),
                $exception instanceof ModelNotFoundException, $exception instanceof NotFoundHttpException => response()->json(['error' => ['code' => 'not_found', 'message' => 'Nothing at this address, or nothing this handset may see.']], 404),
                $exception instanceof MethodNotAllowedHttpException => response()->json(['error' => ['code' => 'method_not_allowed', 'message' => $exception->getMessage()]], 405),
                default => null,
            };
        });

        /*
         * Render errors as a real page rather than Laravel's bare status text.
         *
         * Rule 13: every state names what happened and offers the next action.
         * A stock "403 This action is unauthorized" does neither, and leaves
         * the reader with no way out except editing the URL — which for an
         * estate user bounced off a Gemini route means an unbreakable loop.
         *
         * Debug builds keep the stack trace: hiding a 500 behind friendly copy
         * while developing costs more than it saves.
         */
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            if (app()->hasDebugModeEnabled() && $response->getStatusCode() === 500) {
                return $response;
            }

            if (! in_array($response->getStatusCode(), [403, 404, 419, 429, 500, 503], true)) {
                return $response;
            }

            return inertia('ErrorPage', ['status' => $response->getStatusCode()])
                ->toResponse($request)
                ->setStatusCode($response->getStatusCode());
        });
    })->create();
