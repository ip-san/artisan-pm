<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use App\Enums\UserStatus;
use App\Models\AuthSource;
use App\Models\Group;
use Illuminate\Support\Collection;

/**
 * The filterable/sortable fields of the administrator's user list —
 * Redmine's UserQuery. Native columns go through NativeColumnFilter like the
 * other lists; group membership needs a relation, see UserGroupFilter.
 */
final class UserFilterFieldRegistry
{
    /**
     * @return Collection<string, FilterableField>
     */
    public static function all(): Collection
    {
        $choice = [FilterOperator::Equals, FilterOperator::NotEquals, FilterOperator::In, FilterOperator::NotIn];
        $text = [FilterOperator::Contains, FilterOperator::ContainsAny, FilterOperator::NotContains, FilterOperator::StartsWith, FilterOperator::EndsWith, FilterOperator::Equals, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty];
        $date = [FilterOperator::Equals, FilterOperator::GreaterOrEqual, FilterOperator::LessOrEqual, FilterOperator::Between, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty];

        /** @var array<int, FilterableField> $fields */
        $fields = [
            new NativeColumnFilter('status', __('ステータス'), 'status', FilterFieldType::Select, $choice, fn () => [
                UserStatus::Active->value => __('有効'),
                UserStatus::Registered->value => __('承認待ち'),
                UserStatus::Locked->value => __('ロック中'),
            ]),
            new NativeColumnFilter('auth_source_id', __('認証方式'), 'auth_source_id', FilterFieldType::Select, [...$choice, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty], fn () => AuthSource::query()->orderBy('name')->pluck('name', 'id')->all()),
            new UserGroupFilter,
            new NativeColumnFilter('name', __('名前'), 'name', FilterFieldType::Text, $text),
            new NativeColumnFilter('login', __('ログインID'), 'login', FilterFieldType::Text, $text),
            new NativeColumnFilter('firstname', __('名'), 'firstname', FilterFieldType::Text, $text),
            new NativeColumnFilter('lastname', __('姓'), 'lastname', FilterFieldType::Text, $text),
            new NativeColumnFilter('email', __('メールアドレス'), 'email', FilterFieldType::Text, $text),
            new NativeColumnFilter('is_admin', __('管理者'), 'is_admin', FilterFieldType::Boolean, [FilterOperator::Equals]),
            new NativeColumnFilter('created_at', __('登録日'), 'created_at', FilterFieldType::Date, $date, storesTime: true),
            new NativeColumnFilter('last_login_at', __('最終ログイン'), 'last_login_at', FilterFieldType::Date, $date, storesTime: true),
        ];

        return collect($fields)->keyBy(fn (FilterableField $field) => $field->key());
    }

    /**
     * @return array<string, string> column key => heading
     */
    public static function columns(): array
    {
        return [
            'name' => __('名前'),
            'login' => __('ログインID'),
            'lastname' => __('姓'),
            'firstname' => __('名'),
            'email' => __('メールアドレス'),
            'is_admin' => __('管理者'),
            'status' => __('ステータス'),
            'auth_source_id' => __('認証方式'),
            'created_at' => __('登録日'),
            'last_login_at' => __('最終ログイン'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function defaultColumns(): array
    {
        return ['name', 'email', 'is_admin', 'status', 'auth_source_id'];
    }

    /**
     * Group ids for the group filter's choices, kept here so the filter and
     * the list agree on what a group is.
     *
     * @return array<int|string, string>
     */
    public static function groupOptions(): array
    {
        return Group::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
