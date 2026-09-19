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
 */
final class MailHandlerController extends Controller
{
    public function __invoke(Request $request, IncomingMailService $mail): Response
    {
        $raw = (string) $request->input('email', '');

        if ($raw === '') {
            return response('', 422);
        }

        try {
            $handled = $mail->processRawMessage($raw) !== null;
        } catch (Throwable) {
            $handled = false;
        }

        return response('', $handled ? 201 : 422);
    }
}
