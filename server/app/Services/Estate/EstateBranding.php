<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\EstateSetting;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The estate's mark, and where it lives.
 *
 * THE OLD REASON ASKED FOR THREE THINGS and this is all three: a size and format
 * rule (the constants below, stated on the screen before the press), a stored
 * original (the bytes as uploaded, never re-encoded — a logo re-saved at a
 * smaller size is a logo an estate cannot get back), and somewhere for the old
 * one to go (nowhere: it stays, and the swap is audited with both paths).
 *
 * PRIVATE DISK, ESTATE-PREFIXED PATH. The migration's own comment says one
 * estate's logo "cannot be served from another's URL", and that is enforced by
 * where the file is and by the route being inside the estate's auth group —
 * never by the filename being hard to guess.
 *
 * A PDF EMBEDS THE BYTES AT RENDER TIME, so a statement issued in March keeps
 * March's mark whatever happens here afterwards. That is why nothing deletes.
 *
 * NEVER AN SVG, AND THE SCREEN NO LONGER SAYS OTHERWISE (bug hunt B073). The
 * rule once promised "PNG, JPG or SVG" and the file picker offered SVG, while
 * the upload's `image` rule refused every one — a screen claiming a format the
 * server never took. The refusal is the half that was right: this mark is
 * served inline from the estate's own hostname, and an SVG is a document that
 * can carry script, so accepting one would be stored cross-site scripting on
 * every committee member who opened board 21. So the promise went, not the
 * refusal. And what a file IS is read from its bytes, never from the name it
 * arrived with — a PNG called "mark.svg" is stored and served as a PNG — and a
 * stored file of any other type, however it got there, is neither served nor
 * embedded.
 */
class EstateBranding
{
    /** 2 MB. A mark on a letterhead, not a photograph. */
    public const MAX_KB = 2048;

    /** @var list<string> */
    public const EXTENSIONS = ['png', 'jpg', 'jpeg'];

    /** What the screen says before the press, so a refusal is never a surprise. */
    public const RULES = 'PNG or JPG, up to 2 MB, kept exactly as uploaded. The logo it replaces is kept too, so documents already issued keep the mark they were printed with.';

    public function __construct(private readonly AuditLogger $audit) {}

    public function store(?UploadedFile $file, mixed $by): string
    {
        if ($file === null || ! $file->isValid()) {
            throw new DomainException('That file did not arrive intact. Try it again.');
        }

        // From the bytes, the same reading the upload's `mimes` rule makes —
        // never the client's name for it, which is only a claim.
        $extension = strtolower((string) $file->guessExtension());

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new DomainException(sprintf(
                'A logo is a PNG or a JPG. %s is not one this platform prints.',
                $extension === '' ? 'That file' : strtoupper($extension),
            ));
        }

        $setting = EstateSetting::query()->firstOrFail();
        $previous = $setting->logo_path;

        // Random rather than "logo.png": two uploads must not collide, and an
        // old file must stay reachable by the path already recorded.
        $path = $this->prefix().'/logo-'.Str::random(16).'.'.$extension;

        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        $setting->forceFill(['logo_path' => $path])->save();

        $this->audit->record(
            action: 'estate.logo_changed',
            entityType: 'EstateSetting',
            entityId: (string) $setting->id,
            before: ['logo_path' => $previous],
            after: ['logo_path' => $path, 'bytes' => $file->getSize()],
        );

        return $path;
    }

    /**
     * The bytes, for a PDF to embed. Null when the estate has no mark yet — and
     * when the stored file is not a type this platform prints, which is treated
     * as no mark rather than handed on under a type it is not.
     */
    public function contents(): ?string
    {
        $path = EstateSetting::query()->value('logo_path');

        if ($path === null || self::typeOf((string) $path) === null || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return Storage::disk('local')->get($path);
    }

    /**
     * The logo as a data: URI, which is how a PDF renderer takes it.
     *
     * EMBEDDED, NEVER LINKED. dompdf fetching a URL would mean the renderer
     * making an HTTP request back into the application — from a queue worker,
     * without a session, against a host it may not resolve — and a statement
     * whose logo silently failed to load is one nobody notices until a resident
     * holds it.
     */
    public function dataUri(): ?string
    {
        $bytes = $this->contents();

        if ($bytes === null) {
            return null;
        }

        $type = self::typeOf((string) EstateSetting::query()->value('logo_path'));

        return $type === null ? null : 'data:'.$type.';base64,'.base64_encode($bytes);
    }

    /**
     * The mark itself, inline, from the estate's own hostname.
     *
     * `nosniff`, so a browser renders the bytes as the type they are labelled
     * and never guesses a document out of them.
     */
    public function stream(): StreamedResponse
    {
        $bytes = $this->contents();
        $type = self::typeOf((string) EstateSetting::query()->value('logo_path'));

        abort_if($bytes === null || $type === null, 404);

        return response()->stream(function () use ($bytes): void {
            echo $bytes;
        }, 200, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * The one type each stored mark is served and embedded as, or null for a
     * file that is not one this platform prints — never an SVG, whatever name
     * it was stored under.
     */
    private static function typeOf(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };
    }

    /** Where this estate's files live. Never another estate's. */
    private function prefix(): string
    {
        return 'estates/'.tenant()->getTenantKey().'/branding';
    }
}
