<?php

declare(strict_types=1);

namespace App\Support\Seeded;

use App\Services\Platform\MailLog;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * NO MESSAGE REACHES A PERSON A SEEDER INVENTED (M35 §5.2, layer 1).
 *
 * The platform emailed `andrea.fletcher@email.com` — an address the demonstration seed made up, on a live public mail
 * service — twice, about a panic. Whoever owns that mailbox was told a panic alarm was raised at an estate in Jamaica.
 *
 * ONE PLACE PER CHANNEL, so no sender can forget it:
 *   EMAIL  Laravel's `MessageSending`, which every email passes through on its way to the mail server — a notification,
 *          an on-demand one, a raw test send alike (`AppServiceProvider::boot()`).
 *   SMS    the one text-message driver every text is sent through (`SmsGateway`, wrapped where it is bound).
 * Each recipient is looked up (`SeededPeople`): when the only rows holding the address are people a seeder invented,
 * that recipient is DROPPED — taken off the message, and the message not sent at all when nobody is left, so the
 * mail server and the SMS provider never see it. A row in `seeded_recipient_drops` COUNTS it; a log line names the kind
 * of message, the row and the estate. NEVER THE ADDRESS OR THE NUMBER, in either.
 *
 * ON EVERYWHERE, production included, and NOTHING SWITCHES IT OFF: there is no setting to read.
 *
 * A REAL PERSON IS NEVER AFFECTED. A row a person, a phone or the office wrote carries no mark and is in no seed range;
 * and when anybody real holds the same address as a seeded row, the address is theirs and the message goes.
 *
 * PUSH IS NOT DROPPED, on purpose. A push goes to a phone, and a phone has a push address only after somebody signed in
 * on it — with a code the office issued, or the reviewer's. The demonstration's phones are signed in as seeded officers
 * (Andre Chambers, Kemar Blake…), and dropping their pushes would silence the panic ring the demonstration shows. A
 * stranger cannot sign in to a seeded account: its sign-in code goes by email or text, and those are dropped here.
 *
 * A LOOKUP THAT FAILS SENDS. A panic email must never be lost to a fault in this check; the fault is reported.
 */
final class SeededRecipients
{
    /** @var list<array{channel: string, message: string, connection: string, table: string, id: int, tenant: string|null}>|null */
    private static ?array $watched = null;

    /**
     * THE MAIL TRANSPORT'S GUARD. Null lets the message go (with any seeded recipient taken off it); false stops it.
     */
    public static function screenMail(MessageSending $event): ?bool
    {
        $message = $event->message;
        $notification = $event->data['__laravel_notification'] ?? null;
        $what = MailLog::typeOf(is_string($notification) ? $notification : null);
        $people = new SeededPeople;
        $keep = [];
        $everyone = 0;
        $dropped = 0;

        foreach (['To' => $message->getTo(), 'Cc' => $message->getCc(), 'Bcc' => $message->getBcc()] as $header => $addresses) {
            $keep[$header] = [];

            foreach ($addresses as $address) {
                $everyone++;
                $row = self::seededOnly(static fn (): array => $people->holdersOfEmail($address->getAddress()));

                if ($row === null) {
                    $keep[$header][] = $address;

                    continue;
                }

                $dropped++;
                self::record('mail', $what, $row);
                MailLog::notSent($message, $what, self::label($row), $row['tenant']);
            }
        }

        if ($dropped === 0) {
            return null;
        }

        // Nobody left: the message never reaches the transport.
        if ($dropped === $everyone) {
            return false;
        }

        foreach ($keep as $header => $addresses) {
            self::setRecipients($message, $header, $addresses);
        }

        return null;
    }

    /**
     * THE TEXT-MESSAGE DRIVER'S GUARD: the row the number belongs to when only a seeded person holds it — the text is
     * then not sent, and the drop is counted — or null to send it.
     *
     * @return array{connection: string, table: string, id: int, tenant: string|null}|null
     */
    public static function screenNumber(string $to, string $what = 'Text message'): ?array
    {
        $people = new SeededPeople;
        $row = self::seededOnly(static fn (): array => $people->holdersOfPhone($to));

        if ($row !== null) {
            self::record('sms', $what, $row);
        }

        return $row;
    }

    /**
     * A person a panic was going to write to, asked about by their own row: is it one a seeder invented? The panic's
     * household legs leave such a person out of the people they say they are telling (and `leftOut()` counts it), so
     * nobody is told "2 emailed" about a household with one real person in it. A check that fails answers no.
     */
    public static function isSeededEstatePerson(string $tenant, string $table, int $id, mixed $seededAt): bool
    {
        try {
            return (new SeededPeople)->estateRowIsSeeded($tenant, $table, $id, $seededAt);
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return false;
        }
    }

    /** A seeded person a caller left out before sending: counted and logged as every other drop is. */
    public static function leftOut(string $channel, string $what, string $tenant, string $table, int $id): void
    {
        self::record($channel, $what, ['connection' => 'tenant', 'table' => $table, 'id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Run `$send` and answer what was dropped while it ran — for a caller that keeps its own ledger of what it sent.
     *
     * @template T
     *
     * @param  callable(): T  $send
     * @return array{0: T, 1: list<array{channel: string, message: string, connection: string, table: string, id: int, tenant: string|null}>}
     */
    public static function whileWatching(callable $send): array
    {
        $outer = self::$watched;
        self::$watched = [];

        try {
            $result = $send();

            return [$result, self::$watched];
        } finally {
            self::$watched = $outer === null ? null : [...$outer, ...(self::$watched ?? [])];
        }
    }

    /**
     * "residents #92 at phoenixparkvillage1" — the row, never the address.
     *
     * @param  array{table: string, id: int, tenant: string|null}  $row
     */
    public static function label(array $row): string
    {
        return $row['table'].' #'.$row['id'].($row['tenant'] === null ? '' : ' at '.$row['tenant']);
    }

    /**
     * @param  callable(): array{seeded: list<array{connection: string, table: string, id: int, tenant: string|null}>, real: int}  $find
     * @return array{connection: string, table: string, id: int, tenant: string|null}|null
     */
    private static function seededOnly(callable $find): ?array
    {
        try {
            $holders = $find();
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return null;
        }

        return $holders['real'] === 0 && $holders['seeded'] !== [] ? $holders['seeded'][0] : null;
    }

    /** @param  array{connection: string, table: string, id: int, tenant: string|null}  $row */
    private static function record(string $channel, string $what, array $row): void
    {
        $drop = ['channel' => $channel, 'message' => $what, ...$row];

        if (self::$watched !== null) {
            self::$watched[] = $drop;
        }

        Log::warning('message.not_sent_to_a_seeded_person', [
            'channel' => $channel,
            'message' => $what,
            'row' => $row['connection'].'.'.$row['table'].'#'.$row['id'],
            'tenant' => $row['tenant'],
            'why' => 'the recipient is a person a seeder invented (M35 §5)',
        ]);

        rescue(static fn () => DB::connection('mysql')->table('seeded_recipient_drops')->insert([
            'channel' => $channel,
            'message' => mb_substr($what, 0, 80),
            'row_connection' => $row['connection'],
            'row_table' => $row['table'],
            'row_id' => $row['id'],
            'tenant_id' => $row['tenant'],
            'dropped_at' => Carbon::now(),
        ]), report: false);
    }

    /** @param  list<Address>  $addresses */
    private static function setRecipients(Email $message, string $header, array $addresses): void
    {
        if ($addresses === []) {
            $message->getHeaders()->remove($header);

            return;
        }

        match ($header) {
            'To' => $message->to(...$addresses),
            'Cc' => $message->cc(...$addresses),
            default => $message->bcc(...$addresses),
        };
    }
}
