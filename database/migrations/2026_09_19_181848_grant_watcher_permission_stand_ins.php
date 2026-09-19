<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Redmine's per-object watcher permissions. Until now seeing the watcher
     * list needed only view access, and adding or removing watchers shared
     * one permission (add_issue_watchers, edit_wiki_pages, edit_messages).
     * Each new permission goes to the roles that held its stand-in, so no
     * role gains or loses anything.
     *
     * @var array<string, string>
     */
    private const array STAND_INS = [
        'view_issue_watchers' => 'view_issues',
        'delete_issue_watchers' => 'add_issue_watchers',
        'view_wiki_page_watchers' => 'view_wiki_pages',
        'add_wiki_page_watchers' => 'edit_wiki_pages',
        'delete_wiki_page_watchers' => 'edit_wiki_pages',
        'view_message_watchers' => 'view_messages',
        'add_message_watchers' => 'edit_messages',
        'delete_message_watchers' => 'edit_messages',
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
