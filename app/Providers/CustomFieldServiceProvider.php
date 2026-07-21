<?php

declare(strict_types=1);

namespace App\Providers;

use App\CustomFields\FormatRegistry;
use App\CustomFields\Formats\BoolFormat;
use App\CustomFields\Formats\DateFormat;
use App\CustomFields\Formats\FloatFormat;
use App\CustomFields\Formats\IntFormat;
use App\CustomFields\Formats\ListFormat;
use App\CustomFields\Formats\StringFormat;
use App\CustomFields\Formats\TextFormat;
use App\Enums\CustomizableType;
use App\Models\Issue;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

final class CustomFieldServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FormatRegistry::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(FormatRegistry::class);

        $registry->register(new StringFormat);
        $registry->register(new TextFormat);
        $registry->register(new IntFormat);
        $registry->register(new FloatFormat);
        $registry->register(new DateFormat);
        $registry->register(new BoolFormat);
        $registry->register(new ListFormat);

        // custom_field_values.customized_type stores the short CustomizableType
        // value (e.g. "issue"), matching custom_fields.customized_type, rather
        // than the FQCN Eloquent's morph relations default to.
        Relation::enforceMorphMap([
            CustomizableType::Issue->value => Issue::class,
        ]);
    }
}
