<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A version-control command couldn't run at all: the binary is missing,
 * proc_open is disabled (common on shared hosting), the working directory
 * is gone, or the command ran past its timeout. Thrown by ScmCommand so a
 * repository page shows a message instead of a 500, and so a sync run can
 * skip the repository and go on with the next one.
 */
final class ScmCommandFailedException extends RuntimeException
{
    public function __construct(public readonly string $tool, string $reason, ?Throwable $previous = null)
    {
        parent::__construct("The {$tool} command could not be run: {$reason}", 0, $previous);
    }

    public static function userMessage(): string
    {
        return __('リポジトリにアクセスできませんでした(バージョン管理のコマンドを実行できないか、時間内に終わりませんでした)。管理者はログを確認してください。');
    }

    /**
     * An environment problem rather than a bug: a warning without the
     * stack trace.
     */
    public function report(): void
    {
        Log::warning($this->getMessage());
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['errors' => [self::userMessage()]], 503);
        }

        return response()->view('errors.scm-unavailable', ['message' => self::userMessage()], 503);
    }
}
