<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\Setting;
use App\Rules\RequiredPasswordCharacterClasses;

/**
 * Redmine's User#random_password as generate_password uses it: at least
 * max(password_min_length + 2, 10) characters with one uppercase letter,
 * one lowercase letter and one digit (and one special character when the
 * administrator requires them), leaving out look-alike characters.
 */
final class RandomPassword
{
    public static function generate(): string
    {
        $length = max((int) Setting::get('password_min_length', 8) + 2, 10);
        $lookAlikes = str_split('0O1l|\'"`*');
        $classes = [range('A', 'Z'), range('a', 'z'), range('0', '9')];

        if (in_array('special_chars', RequiredPasswordCharacterClasses::required(), true)) {
            $classes[] = array_values(array_filter(
                array_map('chr', range(0x20, 0x7E)),
                fn (string $char) => preg_match(RequiredPasswordCharacterClasses::CLASSES['special_chars']['pattern'], $char) === 1,
            ));
        }

        $classes = array_map(fn (array $chars) => array_values(array_diff($chars, $lookAlikes)), $classes);
        $password = [];

        foreach ($classes as $chars) {
            $password[] = $chars[random_int(0, count($chars) - 1)];
        }

        $all = array_merge(...$classes);

        while (count($password) < $length) {
            $password[] = $all[random_int(0, count($all) - 1)];
        }

        for ($i = count($password) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$password[$i], $password[$j]] = [$password[$j], $password[$i]];
        }

        return implode('', $password);
    }
}
