<?php

declare(strict_types=1);

namespace App\Support\Wiki;

/**
 * File names inside the wiki ZIP export, as Redmine's
 * WikiController#archived_wiki_page_filename: a backslash becomes `_`, and
 * so does any of `/ ? % * : | " ' < >` or a line break (runs collapse into
 * one `_`); a name already used gets `(1)`, `(2)` … before the extension.
 */
final class WikiExportFilename
{
    /**
     * @param  array<int, string>  $usedNames  names already put in the archive
     */
    public static function for(string $title, string $extension, array $usedNames): string
    {
        $sanitized = preg_replace('/[\/?%*:|"\'<>\n\r]+/u', '_', str_replace('\\', '_', $title)) ?? $title;
        $name = "{$sanitized}.{$extension}";

        for ($count = 1; in_array($name, $usedNames, true); $count++) {
            $name = "{$sanitized}({$count}).{$extension}";
        }

        return $name;
    }
}
