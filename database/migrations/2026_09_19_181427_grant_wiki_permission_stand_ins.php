<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * view_wiki_edits (history, diffs, annotate, older versions) and
     * delete_wiki_pages_attachments used to be covered by view_wiki_pages
     * and edit_wiki_pages. So no role loses access, each new permission goes
     * to the roles holding its stand-in. manage_wiki (deleting a whole wiki)
     * is deliberately not granted: nobody could do that before.
     *
     * @var array<string, string>
     */
    private const array STAND_INS = [
        'view_wiki_edits' => 'view_wiki_pages',
        'delete_wiki_pages_attachments' => 'edit_wiki_pages',
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
