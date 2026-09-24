<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Enums\UserStatus;
use App\Models\AuthSource;
use App\Models\CustomField;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserImport;
use App\Rules\AllowedEmailDomain;
use App\Rules\UniqueUserValueIgnoringCase;
use App\Support\Import\CsvReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Reads the CSV stored for a UserImport (Redmine's UserImport), maps each
 * row's columns per the stored column_mapping and creates one account per
 * row with the same rules the admin user form applies. A row that fails is
 * recorded in `errors` and skipped; the rest still imports.
 */
final class ImportUsersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly UserImport $import,
    ) {}

    public function handle(): void
    {
        $this->import->update(['status' => ImportStatus::Processing]);

        $disk = Storage::disk('local');

        if (! $disk->exists($this->import->file_path)) {
            $this->import->update([
                'status' => ImportStatus::Failed,
                'errors' => [['row' => 0, 'message' => __('アップロードされたファイルが見つかりません。')]],
            ]);

            return;
        }

        ['header' => $header, 'rows' => $rows] = CsvReader::read($disk->path($this->import->file_path));

        $this->import->update(['total_rows' => count($rows)]);

        $mapping = $this->import->column_mapping;
        $errors = [];
        $imported = 0;
        $customFields = CustomField::query()->where('customized_type', User::customizableType())->orderBy('position')->get();

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +1 for zero-index, +1 for the header row

            try {
                $record = array_combine($header, array_pad($row, count($header), null)) ?: [];

                $this->createUser($record, $mapping, $customFields);

                $imported++;
            } catch (ValidationException $e) {
                $errors[] = ['row' => $rowNumber, 'message' => collect($e->errors())->flatten()->join(' ')];
            } catch (Throwable $e) {
                $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage()];
            }

            $this->import->increment('processed_rows');
        }

        $this->import->update([
            'status' => ImportStatus::Completed,
            'imported_count' => $imported,
            'failed_count' => count($errors),
            'errors' => $errors,
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $mapping
     * @param  Collection<int, CustomField>  $customFields
     */
    private function createUser(array $record, array $mapping, $customFields): User
    {
        $authSourceName = $this->mapped($record, $mapping, 'auth_source');
        $authSource = $authSourceName !== null ? AuthSource::query()->where('name', $authSourceName)->first() : null;

        $statusRaw = $this->mapped($record, $mapping, 'status');
        $status = $statusRaw !== null ? self::statusFrom($statusRaw) : UserStatus::Active;

        if ($status === null) {
            throw new RuntimeException(__('ステータス「:status」は使えません(有効/承認待ち/ロック中)。', ['status' => $statusRaw]));
        }

        $password = $this->mapped($record, $mapping, 'password');

        $data = [
            'name' => $this->mapped($record, $mapping, 'name'),
            'firstname' => $this->mapped($record, $mapping, 'firstname'),
            'lastname' => $this->mapped($record, $mapping, 'lastname'),
            'login' => $this->mapped($record, $mapping, 'login'),
            'email' => $this->mapped($record, $mapping, 'email'),
            'password' => $password,
        ];

        $customFieldInput = [];

        foreach ($customFields as $field) {
            $value = $this->mapped($record, $mapping, "cf_{$field->id}");

            if ($value !== null) {
                $customFieldInput[$field->id] = $field->multiple ? array_map('trim', explode(',', $value)) : $value;
            }
        }

        $rules = [
            ...User::nameRules(),
            'email' => ['required', 'string', 'email', 'max:255', new UniqueUserValueIgnoringCase('email'), new AllowedEmailDomain],
            'login' => ['required', 'string', 'max:'.User::LOGIN_LENGTH_LIMIT, 'regex:'.User::LOGIN_FORMAT_REGEX, new UniqueUserValueIgnoringCase('login')],
            // A directory-backed account never uses a local password; every
            // other one needs a password that meets the site's rules.
            'password' => $authSource === null ? ['required', 'string', Password::default()] : ['nullable'],
            ...CustomField::formValidationRules($customFields),
        ];

        $validated = Validator::make([...$data, 'customFieldValues' => $customFieldInput], $rules)->validate();

        $language = $this->mapped($record, $mapping, 'language');
        $isAdmin = self::isYes($this->mapped($record, $mapping, 'admin'));

        $user = new User([
            ...User::normalizeNameInput(array_intersect_key($validated, array_flip(['name', 'firstname', 'lastname']))),
            'login' => $validated['login'],
            'email' => $validated['email'],
            'password' => $authSource === null ? Hash::make($validated['password']) : Hash::make(Str::random(40)),
            'language' => $language ?: Setting::get('default_language', config('app.locale')),
            'auth_source_id' => $authSource?->id,
            'status' => $status->value,
            'no_self_notified' => Setting::get('default_users_no_self_notified', true),
        ]);
        // is_admin is deliberately not mass-assignable — see User's docblock.
        $user->is_admin = $isAdmin;
        $user->must_change_passwd = $authSource === null && self::isYes($this->mapped($record, $mapping, 'must_change_passwd'));
        $user->save();

        $user->setCustomFieldValues($customFields->filter(fn (CustomField $field) => array_key_exists($field->id, $customFieldInput))->mapWithKeys(fn (CustomField $field) => [$field->id => $validated['customFieldValues'][$field->id] ?? null])->all());

        return $user;
    }

    private static function statusFrom(string $value): ?UserStatus
    {
        return match (mb_strtolower(trim($value))) {
            'active', '有効' => UserStatus::Active,
            'registered', '承認待ち' => UserStatus::Registered,
            'locked', 'ロック中', 'ロック' => UserStatus::Locked,
            default => null,
        };
    }

    private static function isYes(?string $value): bool
    {
        return $value !== null && in_array(mb_strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'はい', 'on'], true);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $mapping
     */
    private function mapped(array $record, array $mapping, string $field): ?string
    {
        $column = $mapping[$field] ?? null;

        if ($column === null || ! array_key_exists($column, $record)) {
            return null;
        }

        $value = $record[$column];
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function failed(Throwable $e): void
    {
        $this->import->update(['status' => ImportStatus::Failed]);
    }
}
