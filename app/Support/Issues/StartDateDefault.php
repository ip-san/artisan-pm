<?php

declare(strict_types=1);

namespace App\Support\Issues;

use App\Models\Setting;
use App\Models\User;
use App\Support\Format\DateTimes;

/**
 * Redmine's default_issue_start_date_to_creation_date for the paths that are
 * not the web form: the REST API and received mail. The web form has always
 * defaulted the start date to today (the setting is on there), so applying it
 * to the other two would change what existing API clients and mail senders
 * get — it needs its own switch, off unless an administrator turns it on.
 */
final class StartDateDefault
{
    /**
     * @param  User|null  $user  whose today it is (Redmine's User.current: the API caller, the mail's sender)
     */
    public static function forApiAndMail(?User $user = null): ?string
    {
        if (Setting::get('default_issue_start_date_to_creation_date', true)
            && Setting::get('default_issue_start_date_for_api_and_mail', false)) {
            return DateTimes::today($user)->toDateString();
        }

        return null;
    }
}
