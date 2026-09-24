<?php

declare(strict_types=1);

namespace App\Support\Issues;

use App\Models\Setting;
use App\Models\User;
use App\Support\Format\DateTimes;

/**
 * Redmine's default_issue_start_date_to_creation_date for the paths that are
 * not the web form: the REST API and received mail. Redmine applies it to
 * every path; this app keeps a second switch for the API and mail, on by
 * default (as Redmine behaves), so an administrator can limit the start date
 * default to the web form.
 */
final class StartDateDefault
{
    /**
     * @param  User|null  $user  whose today it is (Redmine's User.current: the API caller, the mail's sender)
     */
    public static function forApiAndMail(?User $user = null): ?string
    {
        if (Setting::get('default_issue_start_date_to_creation_date', false)
            && Setting::get('default_issue_start_date_for_api_and_mail', true)) {
            return DateTimes::today($user)->toDateString();
        }

        return null;
    }
}
