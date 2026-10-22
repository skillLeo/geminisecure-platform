<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Jobs\Estate\RenderDocument;
use App\Models\Estate\Document;
use App\Models\User;
use App\Services\Exports\Exporter;
use App\Support\CentralQueue;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Asking for a document, and finding the one already asked for (12 §1).
 *
 * SERVER-RENDERED AND QUEUED, which is the ruling and also the only honest
 * shape: a statement is a page of rendered HTML turned into a PDF, and doing
 * that inside the request means an estate with four hundred units waits on the
 * slowest one. So the press records the request, hands back immediately, and a
 * worker writes the file.
 *
 * A SECOND PRESS FINDS THE FIRST DOCUMENT. Somebody who sees nothing after ten
 * seconds presses again; without this, four identical statements exist and the
 * resident has four different file names for one balance. `existing()` is what
 * makes the second press idempotent — within the window, and only while the
 * subject has not changed under it.
 *
 * "HAS NOT CHANGED" IS NOW ASKED, NOT ASSUMED (B027). It used to be only the
 * window, so an office that printed a statement, recorded the payment and pressed
 * again for proof was told it was ready and handed the first PDF, still showing
 * the balance owed. A press now gathers what the document would say
 * (`DocumentRenderer::facts()`) and is handed an earlier one only when that hashes
 * the same as what it says.
 */
class Documents
{
    public function __construct(
        private readonly Exporter $exporter,
        private readonly DocumentRenderer $renderer,
    ) {}

    /**
     * How long a document counts as "the one you just asked for".
     *
     * FIFTEEN MINUTES, and it is deliberately short. A statement is a snapshot
     * of a balance: handing somebody yesterday's when they asked today would be
     * showing them a figure that is no longer true. Long enough to cover a
     * queue that is behind and a reader pressing twice; short enough that
     * "today's statement" means today's.
     */
    public const REUSE_MINUTES = 15;

    /**
     * The kinds whose paper says only what its subject says, nothing of the day it was asked for: a meeting's agenda
     * and its minutes (GV-3). One that would read the same IS the same paper, however long ago it was issued, so a
     * ready one is handed back past the window; "View agenda" used to keep an identical copy, for seven years, on every
     * press more than fifteen minutes after the last. A queued one still has only the window: past it, nothing is
     * producing it.
     */
    public const REUSED_WHILE_UNCHANGED = [Document::AGENDA, Document::MINUTES];

    /**
     * Ask for a document. Returns the one already being made, if there is one.
     *
     * @param  array<string, mixed>  $payload  what the renderer needs that is not on the subject
     */
    public function request(
        string $kind,
        string $title,
        string $filename,
        User $by,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $payload = [],
    ): Document {
        if (! array_key_exists($kind, Document::KIND_LABELS)) {
            throw new DomainException('That is not a kind of document this estate issues.');
        }

        /*
         * WHAT IT WOULD SAY IF IT WERE MADE NOW — the lines, not the PDF, so
         * nothing is rendered here. A subject with nothing to issue (minutes not
         * yet recorded, a count not certified) is refused here too, in the
         * renderer's own words, rather than as a failed document a moment later.
         */
        $facts = DocumentRenderer::fingerprint($this->renderer->facts($kind, $subjectId, $title, $payload));

        $existing = $this->existing($kind, $subjectType, $subjectId, $facts);

        /*
         * RECORDED ON EVERY PRESS, including the one handed an existing document
         * (13 A3). Each is somebody asking for a household's statement or a
         * meeting's minutes, and the trail is of who asked, not of how many
         * files exist.
         */
        if ($existing !== null) {
            $this->exporter->document($existing, 'requested');

            return $existing;
        }

        $document = Document::create([
            'kind' => $kind,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'title' => $title,
            'filename' => $filename,
            'status' => Document::QUEUED,
            'requested_by_id' => $by->getKey(),
            'requested_by_name' => (string) $by->name,

            /*
             * STAMPED AT REQUEST, seven years out, and never derived at read
             * time from a setting — a retention period that can be shortened
             * after the fact is not a retention period (12 §1).
             */
            'retain_until' => Carbon::now()->addYears(Document::RETENTION_YEARS),

            // What it is expected to say; the worker replaces it with what it did say.
            'facts_sha256' => $facts,
        ]);

        $this->exporter->document($document, 'requested');

        /*
         * ON THE PLATFORM'S QUEUE, WHICH IS THE ONE THE WORKER READS (B097). This
         * runs inside an estate request, where tenancy has made the estate's
         * database the default — and an estate database has no `jobs` table. The
         * worker still opens the estate from the payload, so `RequiresTenantContext`
         * holds on the other side.
         *
         * AND A JOB THAT COULD NOT BE QUEUED LEAVES NO DOCUMENT WAITING. The row is
         * committed by now. Left QUEUED, the next press inside the window would be
         * handed it back as "being produced" when nothing anywhere is producing it,
         * and the table is append-only, so it would say so for seven years. It is
         * marked not produced, and the reader is told, in their own words.
         */
        try {
            Bus::dispatch(CentralQueue::pin(new RenderDocument(
                (string) tenant()->getTenantKey(),
                $document->id,
                $payload,
            )));
        } catch (Throwable $unqueued) {
            report($unqueued);

            // Read back through `statusLine()` as "Could not be produced: <this>."
            $document->forceFill([
                'status' => Document::FAILED,
                'failure_reason' => 'it could not be handed to the worker that produces documents',
            ])->save();

            throw new DomainException(
                $document->title.' could not be produced: it could not be handed to the worker that produces documents. '
                .'Nothing was issued, and asking again is safe. If it keeps happening, the platform\'s queue needs attention.'
            );
        }

        return $document;
    }

    /**
     * Keep a file that was built in the request — a pay run's summary or its
     * bank file (13 A2).
     *
     * THE SAME ROW, THE SAME SEVEN YEARS, THE SAME TRIGGERS as a rendered
     * statement. Only the production differs: the bytes exist already, so there
     * is nothing to queue, and the file is written before anybody downloads it.
     * An export that is not kept before it leaves is an export the estate may
     * not be able to produce again in year three.
     *
     * THE SAME BYTES ARE KEPT ONCE. An approved run does not change, so taking
     * its bank file twice produces an identical file, and a second copy would
     * be a second retention clock on one document. Matched on the hash of what
     * was built, so a file that differs in a single byte is kept as its own.
     */
    public function keep(
        string $kind,
        string $title,
        string $filename,
        string $contents,
        string $contentType,
        User $by,
        string $subjectType,
        string $subjectId,
    ): Document {
        if (! array_key_exists($kind, Document::KIND_LABELS)) {
            throw new DomainException('That is not a kind of document this estate issues.');
        }

        $sha256 = hash('sha256', $contents);

        $kept = Document::query()
            ->where('kind', $kind)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('status', Document::READY)
            ->where('sha256', $sha256)
            ->first();

        if ($kept !== null) {
            return $kept;
        }

        $document = Document::create([
            'kind' => $kind,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'title' => $title,
            'filename' => $filename,
            'content_type' => $contentType,
            'status' => Document::QUEUED,
            'requested_by_id' => $by->getKey(),
            'requested_by_name' => (string) $by->name,
            'retain_until' => Carbon::now()->addYears(Document::RETENTION_YEARS),
        ]);

        $path = 'estates/'.tenant()->getTenantKey().'/documents/'.$document->id.'-'.$filename;

        Storage::disk('local')->put($path, $contents);

        /*
         * READY only once the bytes on disk hash to what was built. A write that
         * landed short would otherwise be a retained document that is not the
         * file that left.
         */
        $written = (string) Storage::disk('local')->get($path);

        if (hash('sha256', $written) !== $sha256) {
            $document->forceFill([
                'status' => Document::FAILED,
                'failure_reason' => 'The kept copy did not match the file that was built.',
            ])->save();

            throw new DomainException('The file could not be kept, so it was not released. Try the export again.');
        }

        $document->forceFill([
            'status' => Document::READY,
            'path' => $path,
            'bytes' => strlen($contents),
            'sha256' => $sha256,
            'issued_at' => Carbon::now(),
        ])->save();

        return $document;
    }

    /**
     * The document for this subject that is still current, or null.
     *
     * A FAILED ONE IS NOT CURRENT. Somebody pressing again after a failure is
     * asking for another attempt, and handing them the failure back would be a
     * screen that cannot be got past.
     *
     * NOR IS ONE THAT NO LONGER SAYS WHAT IS TRUE (B027). A statement issued
     * before the payment, minutes printed before the correction, a certificate
     * over a tally since amended: each is matched on the hash of the facts it
     * states, so the window only ever hands back a document that would read the
     * same if it were produced again now. A queued one is matched on what it was
     * expected to say; if the subject moved before the worker reached it, a new one
     * is asked for rather than trusting the race.
     */
    public function existing(string $kind, ?string $subjectType, ?string $subjectId, string $facts): ?Document
    {
        $recent = Carbon::now()->subMinutes(self::REUSE_MINUTES);
        $unchangedIsEnough = in_array($kind, self::REUSED_WHILE_UNCHANGED, true);

        return Document::query()
            ->where('kind', $kind)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('facts_sha256', $facts)
            ->where(static function ($current) use ($recent, $unchangedIsEnough): void {
                $current->where(static fn ($inWindow) => $inWindow
                    ->whereIn('status', [Document::QUEUED, Document::READY])
                    ->where('created_at', '>=', $recent));

                // A meeting's paper that reads the same, however long ago it was issued (GV-3).
                if ($unchangedIsEnough) {
                    $current->orWhere('status', Document::READY);
                }
            })
            ->latest('id')
            ->first();
    }

    /**
     * Every document issued about one subject, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forSubject(string $subjectType, string $subjectId, int $limit = 12): array
    {
        return Document::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(static fn (Document $document): array => [
                'id' => $document->id,
                'kind' => $document->kind,
                'kind_label' => $document->kindLabel(),
                'title' => $document->title,
                'status' => $document->status,
                'status_line' => $document->statusLine(),
                'is_ready' => $document->isReady(),
                'issued_at' => $document->issued_at === null ? null : SiteTime::fmt(SiteTime::local($document->issued_at), 'M j, Y g:i A'),
                'retain_until' => $document->retain_until->format('M j, Y'),
            ])
            ->all();
    }
}
