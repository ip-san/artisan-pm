<?php

namespace App\Models;

use App\Enums\ProjectModuleKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['module'])]
class ProjectModuleAssignment extends Model
{
    protected $table = 'project_modules';

    protected function casts(): array
    {
        return [
            'module' => ProjectModuleKey::class,
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
