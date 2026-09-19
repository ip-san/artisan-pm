<?php

use App\Enums\RoleBuiltin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Saving queries and searching a project used to be open to anyone who
     * could see the project. The new permissions keep that: save_queries goes
     * to every role a signed-in user can have (not Anonymous), search_project
     * to every role that holds view_project (Anonymous included).
     */
    public function up(): void
    {
        DB::table('roles')->get(['id', 'permissions', 'builtin'])->each(function (object $role): void {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            $original = $permissions;

            if ($role->builtin !== RoleBuiltin::Anonymous->value && ! in_array('save_queries', $permissions, true)) {
                $permissions[] = 'save_queries';
            }

            if (in_array('view_project', $permissions, true) && ! in_array('search_project', $permissions, true)) {
                $permissions[] = 'search_project';
            }

            if ($permissions !== $original) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            }
        });
    }

    public function down(): void
    {
        // The new permissions are simply ignored when the code is rolled back.
    }
};
