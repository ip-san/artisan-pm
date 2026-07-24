<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CustomFieldResource;
use App\Models\CustomField;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only, index-only — matching Redmine's own CustomFieldsController,
 * which exposes no show/create/update/delete API action at all. Unlike
 * Tracker/IssueStatus/Role/Enumeration (all "auth-only" gates bypassing
 * their admin-only Policy), Redmine's require_admin has no :except
 * for index here, so this stays admin-only even via the API — same
 * gating shape as GroupController.
 */
final class CustomFieldController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CustomField::class);

        $fields = CustomField::query()
            ->with(['trackers', 'roles'])
            ->orderBy('customized_type')
            ->orderBy('position')
            ->get();

        return CustomFieldResource::collection($fields);
    }
}
