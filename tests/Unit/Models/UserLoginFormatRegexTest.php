<?php

use App\Models\User;

test('the login format regex accepts the characters Redmine allows', function () {
    expect(preg_match(User::LOGIN_FORMAT_REGEX, 'admin'))->toBe(1)
        ->and(preg_match(User::LOGIN_FORMAT_REGEX, 'first.last_name-99@example'))->toBe(1);
});

test('the login format regex rejects a disallowed character', function () {
    expect(preg_match(User::LOGIN_FORMAT_REGEX, 'has space'))->toBe(0);
});

test('the login format regex rejects a trailing newline', function () {
    // PHP's `$` (unlike Redmine's `\z`) allows one trailing newline by
    // default — this is exactly what the `D` modifier on
    // User::LOGIN_FORMAT_REGEX exists to close. A request-level test can't
    // exercise this: the app's global TrimStrings middleware strips
    // trailing whitespace/newlines before validation ever sees the value,
    // so the only way to actually exercise the regex's own behavior is
    // directly, as this test does.
    expect(preg_match(User::LOGIN_FORMAT_REGEX, "admin\n"))->toBe(0);
});
