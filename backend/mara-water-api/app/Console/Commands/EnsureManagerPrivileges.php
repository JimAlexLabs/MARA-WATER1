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
 * Two manager logins that share the manager dashboard, with distinct job titles:
 * - CPA Kathy — day-to-day accountant / warehouse / sales-marketing executive (primary system entry)
 * - ADW — auditor / sales-marketing / MD (oversight; do not confuse with Kathy's sales entries)
 */
class EnsureManagerPrivileges extends Command
{
    protected $signature = 'ops:ensure-manager-privileges
        {--email=CKathy@marawater.com : Kathy login email}
        {--password=Kathy@Mara2026! : Kathy login password}
        {--clear-arrivals : Soft-delete grn/adjust stock moves so Arrivals tab starts fresh}';

    protected $description = 'Ensure CPA Kathy + ADW manager identities (distinct roles), merge manager_ui_locks';

    private const KATHY_EMAIL = 'CKathy@marawater.com';
    private const KATHY_PASSWORD = 'Kathy@Mara2026!';
    private const ADW_EMAIL = 'manager@marawater.com';
    private const ADW_PASSWORD = 'Dee@Mara2026';

    public function handle(): int
    {
        $kathyRole = $this->ensureRole(
            'CPA',
            'CPA / Accountant · Warehouse · Sales & Marketing',
            'CPA Kathy — primary day-to-day entries: sales logging, warehouse/stock adjustments, accounts. Shares manager dashboard with ADW.'
        );
        $adwRole = $this->ensureRole(
            'AUD',
            'Auditor · Sales/Marketing · MD',
            'ADW — auditor / MD oversight. Shares manager dashboard with CPA Kathy; sales she logs are attributed to her login, not Kathy.'
        );

        // Keep AUD name accurate if an older seed used a shorter label.
        if ($adwRole->name !== 'Auditor · Sales/Marketing · MD') {
            $adwRole->update([
                'name' => 'Auditor · Sales/Marketing · MD',
                'description' => 'ADW — auditor / MD oversight. Shares manager dashboard with CPA Kathy.',
            ]);
            $adwRole->refresh();
        }

        $kathyEmail = strtolower(trim((string) $this->option('email'))) ?: strtolower(self::KATHY_EMAIL);
        $kathyPassword = (string) $this->option('password') ?: self::KATHY_PASSWORD;

        $financeDept = Department::where('code', 'FIN')->orWhere('name', 'like', '%Finance%')->first();
        $salesDept = Department::where('code', 'SALES')->orWhere('name', 'like', '%Sales%')->first();

        $this->upsertManager([
            'email' => $kathyEmail,
            'password' => $kathyPassword,
            'first_name' => 'CPA Kathy',
            'last_name' => 'Accounts Manager',
            'role_id' => $kathyRole->id,
            'department_id' => $financeDept?->id,
            'label' => 'CPA Kathy (primary ops entry)',
        ]);

        $this->upsertManager([
            'email' => self::ADW_EMAIL,
            'password' => self::ADW_PASSWORD,
            'first_name' => 'ADW',
            'last_name' => 'Auditor MD',
            'role_id' => $adwRole->id,
            'department_id' => $salesDept?->id,
            'label' => 'ADW (auditor / MD oversight)',
        ]);

        $defaults = SettingsController::defaultManagerUiLocks();
        $existing = Setting::allAsMap()['manager_ui_locks'] ?? [];
        if (! is_array($existing)) {
            $existing = [];
        }
        Setting::putMany(['manager_ui_locks' => array_merge($defaults, $existing)]);
        $this->info('manager_ui_locks merged ('.count(array_merge($defaults, $existing)).' keys).');

        if ($this->option('clear-arrivals')) {
            $n = StockMove::whereIn('move_type', ['grn', 'adjust'])->count();
            StockMove::whereIn('move_type', ['grn', 'adjust'])->delete();
            $this->info("Cleared {$n} arrivals/corrections moves (soft-deleted). Stock balances unchanged.");
        }

        $this->line('');
        $this->info('Accountability reminder:');
        $this->line('  • Sales Officer on each sale = whoever was logged in when it was saved (Auth::id()).');
        $this->line('  • CPA Kathy = day-to-day sales / warehouse / accounts adjustments.');
        $this->line('  • ADW = auditor / MD; same dashboard, separate login — do not treat her rows as Kathy\'s.');

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
