<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AuthSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'host', 'port', 'use_tls', 'base_dn', 'account', 'account_password',
    'attr_login', 'attr_name', 'attr_mail', 'onthefly_register', 'timeout',
])]
final class AuthSource extends Model
{
    /** @use HasFactory<AuthSourceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'use_tls' => 'boolean',
            'onthefly_register' => 'boolean',
            'account_password' => 'encrypted',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * A configured service account means search-then-bind: search for the
     * user's DN as this account, then rebind as it to verify the password.
     * Without one, the login builds the user's DN directly (direct bind).
     */
    public function usesSearchThenBind(): bool
    {
        return $this->account !== null && $this->account !== '';
    }
}
