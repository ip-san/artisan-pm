<?php

use App\Support\Mail\MentionParser;

test('extractLogins finds a single mention', function () {
    expect(MentionParser::extractLogins('Please review this @alice'))->toBe(['alice']);
});

test('extractLogins finds multiple distinct mentions in order of first appearance', function () {
    expect(MentionParser::extractLogins('cc @alice and @bob, thanks @alice'))->toBe(['alice', 'bob']);
});

test('extractLogins accepts dots, underscores, and hyphens in a login', function () {
    expect(MentionParser::extractLogins('@first.last_name-99 take a look'))->toBe(['first.last_name-99']);
});

test('extractLogins trims a single trailing period', function () {
    expect(MentionParser::extractLogins('assigning this to @alice.'))->toBe(['alice']);
});

test('extractLogins does not match an email address', function () {
    // The character preceding "@" must not itself be a word/dot/hyphen
    // character, so "user@example.com" (where "@" is directly preceded by
    // the word character "r") is never treated as a mention start at all.
    expect(MentionParser::extractLogins('contact user@example.com for details'))->toBe([]);
});

test('extractLogins ignores a mention inside a fenced code block', function () {
    expect(MentionParser::extractLogins("see below\n```\n@alice\n```\nend"))->toBe([]);
});

test('extractLogins ignores a mention inside inline code', function () {
    expect(MentionParser::extractLogins('use `@alice` as a placeholder'))->toBe([]);
});

test('extractLogins ignores a mention on a blockquoted line', function () {
    expect(MentionParser::extractLogins("> quoting @alice's earlier comment\nnew text"))->toBe([]);
});

test('extractLogins returns an empty array for null or empty input', function () {
    expect(MentionParser::extractLogins(null))->toBe([])
        ->and(MentionParser::extractLogins(''))->toBe([]);
});

test('newlyMentionedLogins only returns logins absent from the before text', function () {
    expect(MentionParser::newlyMentionedLogins('cc @alice', 'cc @alice and @bob'))->toBe(['bob']);
});

test('newlyMentionedLogins returns nothing when no new mention was added', function () {
    expect(MentionParser::newlyMentionedLogins('cc @alice', 'cc @alice, edited wording'))->toBe([]);
});

test('newlyMentionedLogins treats a null before as no prior mentions', function () {
    expect(MentionParser::newlyMentionedLogins(null, 'cc @alice'))->toBe(['alice']);
});
