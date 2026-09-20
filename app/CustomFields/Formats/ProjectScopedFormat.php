<?php

declare(strict_types=1);

namespace App\CustomFields\Formats;

use App\Models\CustomField;
use App\Models\Project;

/**
 * A format whose choices depend on the record's project (Redmine's
 * RecordList formats): a `user` field offers the project's members, a
 * `version` field the versions the project can use. `options()` and
 * `validationRules()` of such a format are the project-less version, all the
 * candidates; the record's own forms and the API ask with its project.
 */
interface ProjectScopedFormat extends FormatContract
{
    /**
     * @return array<string, string> id => label
     */
    public function optionsFor(CustomField $field, ?Project $project): array;

    /**
     * @return array<int, mixed>
     */
    public function rulesFor(CustomField $field, ?Project $project): array;
}
