<?php

declare(strict_types=1);

namespace App\Support\Scm;

use App\Exceptions\ScmCommandFailedException;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;

/**
 * Runs an adapter's VCS command and turns "the command couldn't run" into
 * ScmCommandFailedException: Symfony Process throws when proc_open is
 * disabled, the working directory is missing or the timeout passes, and a
 * missing binary comes back as the shell's exit code 127 (126: found but
 * not executable). A command that ran and failed (an unknown revision, a
 * path that isn't a repository) is still returned as a failed result,
 * which the adapters treat as "nothing to show".
 */
final class ScmCommand
{
    private const array LAUNCH_FAILURE_EXIT_CODES = [126, 127];

    /**
     * @param  Closure(): ProcessResult  $run
     */
    public static function run(string $tool, Closure $run): ProcessResult
    {
        try {
            $result = $run();
        } catch (ProcessException $exception) {
            throw new ScmCommandFailedException($tool, $exception->getMessage(), $exception);
        }

        if (in_array($result->exitCode(), self::LAUNCH_FAILURE_EXIT_CODES, true)) {
            throw new ScmCommandFailedException($tool, trim($result->errorOutput()) ?: "exit code {$result->exitCode()}");
        }

        return $result;
    }

    /**
     * isAvailable(): a command that can't run means "not available".
     *
     * @param  Closure(): ProcessResult  $run
     */
    public static function succeeds(Closure $run): bool
    {
        try {
            return $run()->successful();
        } catch (ScmCommandFailedException) {
            return false;
        }
    }
}
