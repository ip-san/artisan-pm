<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Redmine splits editing an issue into finer permissions: commenting
     * only, editing your own issues, flagging your own issues private, and
     * managing subtasks. They used to be folded into edit_issues and
     * set_issues_private. Each new permission goes to the roles that held its
     * stand-in, so no role gains or loses anything.
     *
     * @var array<string, string>
     */
    private const array STAND_INS = [
        'add_issue_notes' => 'edit_issues',
        'edit_own_issues' => 'edit_issues',
        'manage_subtasks' => 'edit_issues',
        'set_own_issues_private' => 'set_issues_private',
    ];

    public function up(): void
    {
        DB::table('roles')->get(['id', 'permissions'])->each(function (object $role): void {
            $permissions = json_decode((string) $role->permissions, true) ?: [];

            foreach (self::STAND_INS as $new => $standIn) {
                if (in_array($standIn, $permissions, true) && ! in_array($new, $permissions, true)) {
                    $permissions[] = $new;
                }
            }

            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        });
    }

    public function down(): void
    {
        // The new permissions are simply ignored when the code is rolled back.
    }
};
