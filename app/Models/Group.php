<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasCustomFields;
use App\Enums\CustomizableType;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['name'])]
final class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasCustomFields, HasFactory;

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * @return HasMany<Member, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'members')
            ->withTimestamps();
    }

    public static function customizableType(): CustomizableType
    {
        return CustomizableType::Group;
    }

    /**
     * Unlike Issue/Project/Version, a group has no project/role to scope
     * visibility by — it's a site-wide administrative resource managed
     * exclusively by admins (GroupPolicy denies everyone else), so every
     * Group custom field is simply relevant to every group.
     *
     * @return Collection<int, CustomField>
     */
    public function relevantCustomFields(): Collection
    {
        return CustomField::query()
            ->where('customized_type', CustomizableType::Group)
            ->orderBy('position')
            ->get();
    }
}
