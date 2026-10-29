<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\SettingsController;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StockMove;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Kathy manager login + ADW manager login + seed manager UI locks + clear demo arrivals list.
 */
class EnsureManagerPrivileges extends Command
{
    protected $signature = 'ops:ensure-manager-privileges
        {--email=CKathy@marawater.com : Manager login email}
        {--password=Kathy@Mara2026! : Manager login password}
        {--clear-arrivals : Soft-delete grn/adjust stock moves so Arrivals tab starts fresh}';

    protected $description = 'Ensure Kathy + ADW manager users, merge manager_ui_locks defaults, optional clear arrivals demo';

    private const ADW_EMAIL = 'manager@marawater.com';
    private const ADW_PASSWORD = 'Dee@Mara2026';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->option('email')));
        $password = (string) $this->option('password');

        $role = Role::where('access_tier', 'manager')->orderBy('code')->first()
            ?? Role::whereIn('code', ['SMM', 'MANAGER', 'FO', 'CPA'])->first();

        if (! $role) {
            $this->error('No manager-tier role found.');

            return 1;
        }

        $user = User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($user) {
            if ($user->trashed()) {
                $user->restore();
            }
            $user->update([
                'first_name' => $user->first_name ?: 'Kathy',
                'last_name' => $user->last_name ?: 'Cathy',
                'password_hash' => Hash::make($password),
                'admin_password_note' => $password,
                'role_id' => $role->id,
                'status' => 'active',
            ]);
            $this->info("Updated manager login: {$email}");
        } else {
            User::create([
                'id' => (string) Str::uuid(),
                'first_name' => 'Kathy',
                'last_name' => 'Cathy',
                'email' => $email,
                'password_hash' => Hash::make($password),
                'admin_password_note' => $password,
                'role_id' => $role->id,
                'status' => 'active',
                'phone' => null,
            ]);
            $this->info("Created manager login: {$email}");
        }

        // Always ensure the ADW manager account exists (even when --email is a custom one-off).
        $this->ensureAdwManager($role);

        $defaults = SettingsController::defaultManagerUiLocks();
        $existing = Setting::allAsMap()['manager_ui_locks'] ?? [];
        if (! is_array($existing)) {
            $existing = [];
        }
        $merged = array_merge($defaults, $existing);
        // Force cash + petty + payables locked unless already explicitly unlocked in DB
        // (merge already preserves existing unlocks). Just ensure keys exist.
        Setting::putMany(['manager_ui_locks' => $merged]);
        $this->info('manager_ui_locks merged ('.count($merged).' keys).');

        if ($this->option('clear-arrivals')) {
            $n = StockMove::whereIn('move_type', ['grn', 'adjust'])->count();
            StockMove::whereIn('move_type', ['grn', 'adjust'])->delete();
            $this->info("Cleared {$n} arrivals/corrections moves (soft-deleted). Stock balances unchanged.");
        }

        return 0;
    }

    private function ensureAdwManager(Role $role): void
    {
        $email = self::ADW_EMAIL;
        $password = self::ADW_PASSWORD;

        $user = User::withTrashed()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
        if ($user) {
            if ($user->trashed()) {
                $user->restore();
            }
            $user->update([
                'first_name' => 'Adw',
                'last_name' => 'Manager',
                'password_hash' => Hash::make($password),
                'admin_password_note' => $password,
                'role_id' => $role->id,
                'status' => 'active',
            ]);
            $this->info("Updated ADW manager login: {$email}");
        } else {
            User::create([
                'id' => (string) Str::uuid(),
                'first_name' => 'Adw',
                'last_name' => 'Manager',
                'email' => $email,
                'password_hash' => Hash::make($password),
                'admin_password_note' => $password,
                'role_id' => $role->id,
                'status' => 'active',
                'phone' => null,
            ]);
            $this->info("Created ADW manager login: {$email}");
        }
    }
}
