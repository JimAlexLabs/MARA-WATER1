<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Upsert the live Rongo restart roster (production ladies, driver, manager).
 * Soft-deactivates placeholder "Production LADY N" sims when real names exist.
 */
class UpsertRestartStaffRoster extends Command
{
    protected $signature = 'mara:upsert-restart-staff {--dry-run : Show actions without writing}';

    protected $description = 'Upsert named Mara Water staff (production, driver, manager) with HR/bank fields';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $bp = Role::where('code', 'BP')->first();
        $drv = Role::where('code', 'DRV')->first();
        $smm = Role::where('code', 'SMM')->first();

        if (!$bp || !$drv || !$smm) {
            $this->error('Required roles BP/DRV/SMM missing.');
            return 1;
        }

        $staff = [
            // Production ladies — name order priority
            [
                'first_name' => 'Sharon', 'last_name' => 'Adhiambo', 'role_id' => $bp->id,
                'phone' => '+254705021668', 'id_number' => '35240340',
                'kra_pin' => 'A018599091S', 'nssf_number' => null,
                'shif_number' => 'CR2828491199711-5',
                'bank_name' => 'Equity', 'bank_branch' => null,
                'bank_account_number' => '0980186926103', 'salary' => 10000,
            ],
            [
                'first_name' => 'Ann', 'last_name' => 'Nafula', 'role_id' => $bp->id,
                'phone' => null, 'id_number' => '29022511',
                'kra_pin' => 'A009098651E', 'nssf_number' => '00250211',
                'shif_number' => 'CR9437494893379-2',
                'bank_name' => 'Cooperative Bank', 'bank_branch' => null,
                'bank_account_number' => '01102377326001', 'salary' => 10000,
                // Prefer matching Wanyama if present
                'aka_last' => 'Wanyama',
            ],
            [
                'first_name' => 'Cynthia', 'last_name' => 'Achieng', 'role_id' => $bp->id,
                'phone' => null, 'id_number' => '34730477',
                'kra_pin' => 'A017004274L', 'nssf_number' => '00269247',
                'shif_number' => 'CR6778969492032-6',
                'bank_name' => 'Cooperative Bank', 'bank_branch' => null,
                'bank_account_number' => '01102619997001', 'salary' => 10000,
                'aka_last' => 'Otieno',
            ],
            [
                'first_name' => 'Molly', 'last_name' => 'Akoth', 'role_id' => $bp->id,
                'phone' => null, 'id_number' => '33549054',
                'kra_pin' => 'A011874576J', 'nssf_number' => '20015127',
                'shif_number' => 'CR1248710850335-0',
                'bank_name' => 'Cooperative Bank', 'bank_branch' => null,
                'bank_account_number' => '01102650291001', 'salary' => 10000,
                'aka_last' => 'Gor',
            ],
            [
                'first_name' => 'Janet', 'last_name' => 'Auma', 'role_id' => $bp->id,
                'phone' => null, 'id_number' => '35301452',
                'kra_pin' => 'A020877497Z', 'nssf_number' => '205507248',
                'shif_number' => 'CR7069749290413-4',
                'bank_name' => 'Cooperative Bank', 'bank_branch' => null,
                'bank_account_number' => '01100708457001', 'salary' => 10000,
                'aka_last' => 'Ochieng',
            ],
            [
                'first_name' => 'Sellah', 'last_name' => 'Atieno', 'role_id' => $bp->id,
                'phone' => '+254116931955', 'id_number' => '38819095',
                'kra_pin' => 'A018386561E', 'nssf_number' => null,
                'shif_number' => null,
                'bank_name' => 'Equity', 'bank_branch' => null,
                'bank_account_number' => '098018692621', 'salary' => 10000,
                'aka_first' => 'Atieno',
            ],
            // Driver
            [
                'first_name' => 'Danish', 'last_name' => 'Ochieng', 'role_id' => $drv->id,
                'phone' => '+254712254145', 'id_number' => '30055910',
                'kra_pin' => 'A012783209R', 'nssf_number' => '2056697029',
                'shif_number' => 'CR1739890387062-8',
                'bank_name' => 'Cooperative Bank', 'bank_branch' => 'Homa Bay',
                'bank_account_number' => '01101689231001', 'bank_code' => '11',
                'salary' => 20000, 'aka_last' => 'Oriri',
            ],
            // Manager
            [
                'first_name' => 'Santos', 'last_name' => 'Cathy', 'role_id' => $smm->id,
                'phone' => null, 'id_number' => '683357740',
                'kra_pin' => 'A021400708D', 'nssf_number' => '2064644736',
                'shif_number' => 'CR0412870046989-3',
                'bank_name' => 'Cooperative Bank', 'bank_branch' => null,
                'bank_account_number' => '01102832068001', 'salary' => 25000,
                'aka_last' => 'Omolo',
            ],
        ];

        $displayNames = [
            '35240340' => ['Sharon', 'Adhiambo'],
            '29022511' => ['Ann', 'Nafula Wanyama'],
            '34730477' => ['Cynthia', 'Achieng Otieno'],
            '33549054' => ['Molly', 'Akoth Gor'],
            '35301452' => ['Janet', 'Auma Ochieng'],
            '38819095' => ['Sellah', 'Atieno'],
            '30055910' => ['Danish', 'Ochieng Oriri'],
            '683357740' => ['Santos', 'Cathy Omolo'],
        ];

        $fn = function () use ($staff, $dry, $bp, $displayNames) {
            foreach ($staff as $row) {
                $user = $this->findExisting($row);
                [$fn, $ln] = $displayNames[$row['id_number']] ?? [$row['first_name'], $row['last_name']];
                $payload = [
                    'first_name' => $fn,
                    'last_name' => $ln,
                    'phone' => $row['phone'],
                    'id_number' => $row['id_number'],
                    'kra_pin' => $row['kra_pin'],
                    'nssf_number' => $row['nssf_number'],
                    'shif_number' => $row['shif_number'],
                    'bank_name' => $row['bank_name'],
                    'bank_branch' => $row['bank_branch'] ?? null,
                    'bank_account_number' => $row['bank_account_number'],
                    'bank_code' => $row['bank_code'] ?? null,
                    'salary' => $row['salary'],
                    'house_allowance' => 0,
                    'role_id' => $row['role_id'],
                    'status' => 'active',
                ];

                if ($dry) {
                    $this->line(($user ? 'UPDATE' : 'CREATE').' '.$payload['first_name'].' '.$payload['last_name'].' ID '.$payload['id_number']);
                    continue;
                }

                if ($user) {
                    $user->fill($payload);
                    $user->save();
                    $this->info('Updated '.$user->full_name);
                } else {
                    $user = User::create(array_merge($payload, [
                        'email' => null,
                        'password_hash' => null,
                    ]));
                    $this->info('Created '.$user->full_name);
                }
            }

            if (!$dry) {
                $placeholders = User::where('role_id', $bp->id)
                    ->where('status', 'active')
                    ->where(function ($q) {
                        $q->where('first_name', 'like', 'Production%')
                            ->orWhere('last_name', 'like', 'LADY%');
                    })
                    ->get();
                foreach ($placeholders as $p) {
                    $p->update(['status' => 'inactive']);
                    $this->warn('Deactivated placeholder '.$p->full_name);
                }
            }
        };

        if ($dry) {
            $fn();
            $this->comment('Dry run only — no writes.');
            return 0;
        }

        DB::transaction($fn);
        $this->info('Roster upsert complete.');
        return 0;
    }

    private function findExisting(array $row): ?User
    {
        if (!empty($row['id_number'])) {
            $byId = User::where('id_number', $row['id_number'])->first();
            if ($byId) {
                return $byId;
            }
        }

        $q = User::query()->where('first_name', $row['first_name']);
        $candidates = $q->get()->filter(function (User $u) use ($row) {
            $ln = strtolower($u->last_name ?? '');
            $targets = array_filter([
                strtolower($row['last_name']),
                isset($row['aka_last']) ? strtolower($row['aka_last']) : null,
                isset($row['aka_first']) ? strtolower($row['aka_first']) : null,
            ]);
            foreach ($targets as $t) {
                if ($t && str_contains($ln, $t)) {
                    return true;
                }
            }
            // Danish / Santos exact-ish
            if (strtolower($row['first_name']) === 'danish' && str_contains(strtolower($u->full_name), 'danish')) {
                return true;
            }
            if (strtolower($row['first_name']) === 'santos' && str_contains(strtolower($u->full_name), 'santos')) {
                return true;
            }
            return false;
        });

        return $candidates->first();
    }
}
