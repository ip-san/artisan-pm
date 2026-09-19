<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Redmine's Message-Id scheme for notification mails
 * (Mailer.token_for): `redmine.issue-123.20260919123000.7@example.com`.
 * A later update of the issue carries the issue's own token in References,
 * so mail clients thread the conversation, and a reply's In-Reply-To /
 * References lead back to the issue without depending on the subject.
 */
final class MessageIdentity
{
    /**
     * Same pattern as Redmine's MailHandler::MESSAGE_ID_RE.
     */
    public const string PATTERN = '/^<?redmine\.([a-z0-9_]+)-(\d+)\.\d+(\.[a-f0-9]+)?@/';

    /**
     * The token for `$object`, addressed to `$recipientId` when the mail
     * goes to one specific user. The kind is the model's snake-case class
     * name: issue, journal.
     */
    public static function tokenFor(Model $object, ?int $recipientId = null): string
    {
        $kind = Str::snake(class_basename($object));
        $timestamp = ($object->created_at ?? $object->updated_at)->copy()->utc()->format('YmdHis');

        $parts = ['redmine', "{$kind}-{$object->getKey()}", $timestamp];

        if ($recipientId !== null) {
            $parts[] = (string) $recipientId;
        }

        return implode('.', $parts).'@'.self::host();
    }

    /**
     * The domain of mail_from, else the public host_name, else this
     * machine's name — Redmine falls back to "<hostname>.redmine".
     */
    public static function host(): string
    {
        $fromHost = preg_replace('/^.*@|>/', '', trim((string) Setting::get('mail_from', '')));

        if (is_string($fromHost) && $fromHost !== '') {
            return $fromHost;
        }

        $publicHost = trim((string) Setting::get('host_name', ''), " \t\n\r\0\x0B/");

        if ($publicHost !== '') {
            return explode('/', $publicHost)[0];
        }

        return gethostname().'.redmine';
    }

    /**
     * The object a reply's headers point back to, when one of them carries a
     * Redmine token: [kind, id] — or null when none does.
     *
     * @param  array<int, string>  $headerValues  In-Reply-To and References values
     * @return array{0: string, 1: int}|null
     */
    public static function target(array $headerValues): ?array
    {
        foreach ($headerValues as $value) {
            // References is a space-separated list of ids.
            foreach (preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $id) {
                if (preg_match(self::PATTERN, $id, $matches) === 1) {
                    return [$matches[1], (int) $matches[2]];
                }
            }
        }

        return null;
    }
}
