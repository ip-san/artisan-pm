<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\Setting;
use App\Support\Markdown\WikiMarkdownRenderer;

/**
 * The `emails_header` / `emails_footer` settings as the HTML part of a
 * notification shows them: formatted as Markdown, as Redmine's mailer
 * layout does with Setting.text_formatting. The text part keeps the raw text.
 */
final class EmailDecorations
{
    public static function headerHtml(): string
    {
        return self::html((string) Setting::get('emails_header', ''));
    }

    public static function footerHtml(): string
    {
        return self::html((string) Setting::get('emails_footer', ''));
    }

    private static function html(string $text): string
    {
        return trim($text) === '' ? '' : app(WikiMarkdownRenderer::class)->render($text);
    }
}
