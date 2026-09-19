<?php

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function registrationPayload(array $extra = []): array
{
    return [
        'name' => 'New User', 'login' => 'new-user', 'email' => 'new-user@example.com',
        'password' => 'correct-horse-battery-staple', 'password_confirmation' => 'correct-horse-battery-staple',
        ...$extra,
    ];
}

function registrationField(array $attributes = []): CustomField
{
    return CustomField::factory()->create(['customized_type' => 'user', ...$attributes]);
}

test('by default only required user fields appear on the registration form', function () {
    registrationField(['name' => 'Optional field']);
    registrationField(['name' => 'Required field', 'is_required' => true]);

    $this->get(route('register'))->assertSee('Required field')->assertDontSee('Optional field');
});

test('the setting shows the editable optional fields too, but never a read-only one', function () {
    Setting::set('show_custom_fields_on_registration', true);
    registrationField(['name' => 'Optional field']);
    registrationField(['name' => 'Locked field', 'editable' => false]);

    $this->get(route('register'))->assertSee('Optional field')->assertDontSee('Locked field');
});

test('a required field left empty blocks the registration', function () {
    $field = registrationField(['is_required' => true]);

    $this->post(route('register'), registrationPayload())->assertSessionHasErrors(["customFieldValues.{$field->id}"]);

    expect(User::where('login', 'new-user')->exists())->toBeFalse();
});

test('submitted values are validated and saved on the new user', function () {
    Setting::set('show_custom_fields_on_registration', true);
    $text = registrationField(['name' => 'Department']);
    $number = registrationField(['field_format' => CustomFieldFormat::Int->value]);

    $this->post(route('register'), registrationPayload(['custom_fields' => [$text->id => 'Sales', $number->id => 'abc']]))->assertSessionHasErrors(["customFieldValues.{$number->id}"]);

    $this->post(route('register'), registrationPayload(['custom_fields' => [$text->id => 'Sales', $number->id => '7']]))->assertRedirect();

    $user = User::where('login', 'new-user')->firstOrFail();
    expect($user->customValue($text))->toBe('Sales')->and($user->customValue($number))->toBe(7);
});

test('a field that was not offered cannot be filled by posting its id', function () {
    $hidden = registrationField(['name' => 'Not offered']);

    $this->post(route('register'), registrationPayload(['custom_fields' => [$hidden->id => 'sneaky']]))->assertRedirect();

    expect(User::where('login', 'new-user')->firstOrFail()->customValue($hidden))->toBeNull();
});

test('list and boolean fields render as choices and register correctly', function () {
    Setting::set('show_custom_fields_on_registration', true);
    $list = CustomField::factory()->list(['Red', 'Blue'])->create(['customized_type' => 'user', 'name' => 'Colour']);
    $bool = registrationField(['field_format' => CustomFieldFormat::Bool->value, 'name' => 'Newsletter']);

    $this->get(route('register'))->assertSee('Red')->assertSee('Newsletter');
    $this->post(route('register'), registrationPayload(['custom_fields' => [$list->id => 'Blue', $bool->id => '1']]))->assertRedirect();

    $user = User::where('login', 'new-user')->firstOrFail();
    expect($user->customValue($list))->toBe('Blue')->and($user->customValue($bool))->toBeTrue();
});

test('the settings page saves the registration switch', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')->set('show_custom_fields_on_registration', true)->call('save')->assertHasNoErrors();

    expect(Setting::get('show_custom_fields_on_registration'))->toBeTrue();
});
