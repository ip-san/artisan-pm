<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's time_entries.author_id (who logged the time, as opposed to
     * user_id, whose time it is), and the four permissions that until now
     * were folded into other ones. So no role loses what it could do, each
     * new permission is granted to the roles that held its stand-in.
     *
     * @var array<string, string>
     */
    private const array PERMISSION_STAND_INS = [
        'edit_own_time_entries' => 'log_time',
        'log_time_for_other_users' => 'edit_time_entries',
        'import_time_entries' => 'log_time',
        'import_issues' => 'add_issues',
    ];

    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreignId('author_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });

        // Existing entries were all logged by their own user, or by someone
        // who was never recorded; user_id is the best available answer.
        DB::table('time_entries')->update(['author_id' => DB::raw('user_id')]);

        DB::table('roles')->get(['id', 'permissions'])->each(function (object $role): void {
            $permissions = json_decode((string) $role->permissions, true) ?: [];

            foreach (self::PERMISSION_STAND_INS as $new => $standIn) {
                if (in_array($standIn, $permissions, true) && ! in_array($new, $permissions, true)) {
                    $permissions[] = $new;
                }
            }

            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('author_id');
        });
    }
};
