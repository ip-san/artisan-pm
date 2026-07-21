<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuthSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthSource>
 */
class AuthSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' LDAP',
            'host' => 'localhost',
            'port' => 389,
            'use_tls' => false,
            'base_dn' => 'dc=example,dc=com',
            'account' => null,
            'account_password' => null,
            'attr_login' => 'uid',
            'attr_name' => 'cn',
            'attr_mail' => 'mail',
            'onthefly_register' => false,
            'timeout' => 5,
        ];
    }

    public function searchThenBind(): static
    {
        return $this->state([
            'account' => 'cn=admin,dc=example,dc=com',
            'account_password' => 'secret',
        ]);
    }

    public function onTheFly(): static
    {
        return $this->state(['onthefly_register' => true]);
    }
}
