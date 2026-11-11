<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\SettingsController;
use App\Models\Department;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StockMove;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Manager login for the auditor. Former staff are not recreated.
 */
class EnsureManagerPrivileges extends Command
{
    protected $signature = 'ops:ensure-manager-privileges
        {--clear-arrivals : Soft-delete grn/adjust stock moves so Arrivals tab starts fresh}';

    protected $description = 'Keep the auditor manager login and do not recreate former staff';
    private const ADW_EMAIL = 'manager@marawater.com';
    private const ADW_PASSWORD = 'Dee@Mara2026';

    public function handle(): int
    {
        $this->ensureRole(
            'CPA',
            'CPA / Accountant · Warehouse · Sales & Marketing',
            'Accountant, warehouse and sales entries. A removed employee is not listed here.'
        );
        $adwRole = $this->ensureRole(
            'AUD',
            'Auditor · Sales/Marketing · MD',
            'Auditor and MD oversight. Sales are attributed to the person who was logged in.'
        );

        // Keep AUD name accurate if an older seed used a shorter label.
        if ($adwRole->name !== 'Auditor · Sales/Marketing · MD') {
            $adwRole->update([
                'name' => 'Auditor · Sales/Marketing · MD',
                'description' => 'Auditor and MD oversight.',
            ]);
            $adwRole->refresh();
        }

        $salesDept = Department::where('code', 'SALES')->orWhere('name', 'like', '%Sales%')->first();

        $this->upsertManager([
            'email' => self::ADW_EMAIL,
            'password' => self::ADW_PASSWORD,
            'first_name' => 'ADW',
            'last_name' => 'Auditor MD',
            'role_id' => $adwRole->id,
            'department_id' => $salesDept?->id,
            'label' => 'ADW (auditor / MD oversight)',
        ]);

        // Merge missing keys only — never wipe Admin unlocks back to defaults.
        $merged = \App\Services\ManagerUiLocksService::globalLocks();
        Setting::putMany(['manager_ui_locks' => $merged]);
        $this->info('manager_ui_locks merged ('.count($merged).' keys; existing unlocks preserved).');

        if ($this->option('clear-arrivals')) {
            $n = StockMove::whereIn('move_type', ['grn', 'adjust'])->count();
            StockMove::whereIn('move_type', ['grn', 'adjust'])->delete();
            $this->info("Cleared {$n} arrivals/corrections moves (soft-deleted). Stock balances unchanged.");
        }

        $this->line('');
        $this->info('Accountability reminder:');
        $this->line('  • Sales Officer on each sale = whoever was logged in when it was saved.');
        $this->line('  • Removed employees stay off the roster and are not recreated.');

        return 0;
    }

    private function ensureRole(string $code, string $name, string $description): Role
    {
        $role = Role::where('code', $code)->first();
        if ($role) {
            $role->update([
                'name' => $name,
                'description' => $description,
                'access_tier' => 'manager',
            ]);

            return $role->fresh();
        }

        return Role::create([
            'id' => (string) Str::uuid(),
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'access_tier' => 'manager',
            'is_system' => false,
        ]);
    }

    /**
     * @param  array{email:string,password:string,first_name:string,last_name:string,role_id:string,department_id:?string,label:string}  $spec
     */
    private function upsertManager(array $spec): void
    {
        $email = strtolower(trim($spec['email']));
        $user = User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->first();
        $payload = [
            'first_name' => $spec['first_name'],
            'last_name' => $spec['last_name'],
            'password_hash' => Hash::make($spec['password']),
            'admin_password_note' => $spec['password'],
            'role_id' => $spec['role_id'],
            'status' => 'active',
        ];
        if (! empty($spec['department_id'])) {
            $payload['department_id'] = $spec['department_id'];
        }

        if ($user) {
            if ($user->trashed()) {
                $user->restore();
            }
            $user->update($payload);
            $this->info("Updated {$spec['label']}: {$email}");
        } else {
            User::create(array_merge($payload, [
                'id' => (string) Str::uuid(),
                'email' => $email,
                'phone' => null,
            ]));
            $this->info("Created {$spec['label']}: {$email}");
        }
    }
}
