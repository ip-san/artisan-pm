<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\IncomingMailService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Redmine's `POST /mail_handler`: a mail hook (rdm-mailhandler.rb, a mail
 * server pipe) posts the raw message in `email`. Answers 201 when the mail
 * created or updated something, 422 when it was not accepted. Guarded by the
 * shared key, not a login — see EnforceMailHandlerApiKey.
 *
 * Also accepts, like Redmine's own MailHandlerController#index, a
 * per-request `allow_override` (a comma-separated attribute list, same
 * grammar as the site's mail_handler_allow_override setting — used
 * instead of that setting for this one request) and `issue[...]` default
 * attribute values (status/tracker/category/priority/assigned_to/
 * fixed_version/is_private — Redmine's own permitted param list, minus
 * `project`: this app resolves the target project from the subject/
 * recipient before any keyword handling, not through this fallback).
 */
final class MailHandlerController extends Controller
{
    public function __invoke(Request $request, IncomingMailService $mail): Response
    {
        $raw = (string) $request->input('email', '');

        if ($raw === '') {
            return response('', 422);
        }

        $options = [
            ...($request->has('allow_override') ? ['allow_override' => (string) $request->input('allow_override')] : []),
            'issue' => array_filter(
                (array) $request->input('issue', []),
                fn ($value, $key) => is_string($value) && in_array($key, ['status', 'tracker', 'category', 'priority', 'assigned_to', 'fixed_version', 'is_private'], true),
                ARRAY_FILTER_USE_BOTH,
            ),
        ];

        try {
            $handled = $mail->processRawMessage($raw, $options) !== null;
        } catch (Throwable) {
            $handled = false;
        }

        return response('', $handled ? 201 : 422);
    }
}
