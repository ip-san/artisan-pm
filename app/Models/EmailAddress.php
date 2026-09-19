<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmailAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An additional email address of a user (Redmine's EmailAddress, minus the
 * primary one, which lives in users.email).
 */
#[Fillable(['user_id', 'address', 'notify'])]
final class EmailAddress extends Model
{
    /** @use HasFactory<EmailAddressFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'notify' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
