<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserDashboardBlockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'block_key', 'position', 'settings'])]
final class UserDashboardBlock extends Model
{
    /** @use HasFactory<UserDashboardBlockFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
