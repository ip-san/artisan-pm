<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Redmine has finer project permissions than this app used to: choosing
     * whether a project is public, managing the project's activities and
     * listing its members were all folded into edit_project / manage_members.
     * Each new permission goes to the roles that held its stand-in, so no role
     * gains or loses anything.
     *
     * @var array<string, string>
     */
    private const array STAND_INS = [
        'select_project_publicity' => 'edit_project',
        'manage_project_activities' => 'edit_project',
        'view_members' => 'manage_members',
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
