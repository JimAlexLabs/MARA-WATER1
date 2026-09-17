<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Role;
use App\Models\Department;

class UserController extends Controller
{
    private const SENSITIVE_STAFF_FIELDS = [
        'salary', 'house_allowance', 'gross_salary',
        'bank_name', 'bank_branch', 'bank_account_number', 'bank_code',
        'kra_pin', 'nssf_number', 'shif_number',
    ];

    public function index(Request $request)
    {
        try {
            $query = User::with(['role', 'department']);

            // Search
            if ($request->has('search')) {
                $search = $request->get('search');
                $query->where(function($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            // Filter by role
            if ($request->has('role_id')) {
                $query->where('role_id', $request->get('role_id'));
            }

            // Filter by department
            if ($request->has('department_id')) {
                $query->where('department_id', $request->get('department_id'));
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->get('status'));
            }

            // Sort
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Paginate
            $perPage = $request->get('per_page', 15);
            $users = $query->paginate($perPage);
            $items = $users->items();

            // Round 2 Phase 4: salary was already hidden from non-admins;
            // extended the same treatment to the new payroll/PII fields
            // (house allowance, gross salary, bank details, KRA/NSSF/SHIF
            // numbers) rather than accidentally exposing them to every
            // logged-in user just because they're now columns on User.
            if (!$request->user()->isDirector()) {
                foreach ($items as $u) {
                    $u->makeHidden(self::SENSITIVE_STAFF_FIELDS);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $items
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            // email/password are optional -- most staff (drivers, storekeepers,
            // packaging crew) are employee records with no need to log into
            // the app. No password_hash means they simply can't log in.
            $validator = Validator::make($request->all(), [
                'email' => 'nullable|email|unique:users,email',
                'phone' => 'required|string|max:20',
                'password' => 'nullable|string|min:8',
                'first_name' => 'required|string|max:50',
                'last_name' => 'required|string|max:50',
                'role_id' => 'required|exists:roles,id',
                'department_id' => 'required|exists:departments,id',
                'id_number' => 'nullable|string|max:20|unique:users,id_number',
                'staff_number' => 'nullable|string|max:20|unique:users,staff_number',
                'address' => 'nullable|string|max:2000',
                'kra_pin' => 'nullable|string|max:20',
                'nssf_number' => 'nullable|string|max:20',
                'shif_number' => 'nullable|string|max:20',
                'date_of_birth' => 'nullable|date',
                'terms_of_employment' => 'nullable|string|max:50',
                'employment_date' => 'nullable|date',
                'salary' => 'nullable|numeric|min:0',
                'house_allowance' => 'nullable|numeric|min:0',
                'bank_name' => 'nullable|string|max:100',
                'bank_branch' => 'nullable|string|max:100',
                'bank_account_number' => 'nullable|string|max:50',
                'bank_code' => 'nullable|string|max:20',
                'status' => 'sometimes|in:active,inactive,suspended'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::create([
                'id' => Str::uuid(),
                'email' => $request->email ?: null,
                'phone' => $request->phone,
                'password_hash' => $request->filled('password') ? Hash::make($request->password) : null,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'role_id' => $request->role_id,
                'department_id' => $request->department_id,
                'id_number' => $request->id_number ?: null,
                'staff_number' => $request->staff_number ?: null,
                'address' => $request->address ?: null,
                'kra_pin' => $request->kra_pin ?: null,
                'nssf_number' => $request->nssf_number ?: null,
                'shif_number' => $request->shif_number ?: null,
                'date_of_birth' => $request->date_of_birth ?: null,
                'terms_of_employment' => $request->terms_of_employment ?: null,
                'employment_date' => $request->employment_date ?: null,
                'salary' => $request->salary ?: null,
                'house_allowance' => $request->house_allowance ?: 0,
                'bank_name' => $request->bank_name ?: null,
                'bank_branch' => $request->bank_branch ?: null,
                'bank_account_number' => $request->bank_account_number ?: null,
                'bank_code' => $request->bank_code ?: null,
                'status' => $request->status ?? 'active',
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $user->load(['role', 'department']);

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => $user
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk-add every current employee in one sitting: pasted spreadsheet
     * rows or a CSV upload, parsed client-side into a plain array of
     * {first_name, last_name, phone, id_number, department, role,
     * employment_date, salary, status}. department/role are matched here
     * (case-insensitively) against existing code or name -- callers don't
     * need to know the underlying UUIDs. Rows that don't resolve are
     * reported back per-row rather than failing the whole batch, so a typo
     * in row 40 doesn't block the other 39 from being created.
     */
    public function bulkStore(Request $request)
    {
        if (!$request->user()->isDirector()) {
            return response()->json([
                'success' => false,
                'message' => 'Only an administrator can bulk-import employees',
            ], 403);
        }

        $rows = $request->input('employees', []);
        if (!is_array($rows) || count($rows) === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No employee rows provided',
            ], 422);
        }
        if (count($rows) > 500) {
            return response()->json([
                'success' => false,
                'message' => 'Too many rows in one batch (max 500). Split into smaller batches.',
            ], 422);
        }

        $rolesByCode = Role::all()->keyBy(fn ($r) => strtolower($r->code));
        $rolesByName = Role::all()->keyBy(fn ($r) => strtolower($r->name));
        $deptsByCode = Department::all()->keyBy(fn ($d) => strtolower($d->code));
        $deptsByName = Department::all()->keyBy(fn ($d) => strtolower($d->name));

        $created = [];
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                $rowNum = $i + 1;
                $firstName = trim((string) ($row['first_name'] ?? ''));
                $lastName = trim((string) ($row['last_name'] ?? ''));
                $phone = trim((string) ($row['phone'] ?? ''));
                $roleKey = strtolower(trim((string) ($row['role'] ?? '')));
                $deptKey = strtolower(trim((string) ($row['department'] ?? '')));

                if ($firstName === '' || $lastName === '') {
                    $errors[] = ['row' => $rowNum, 'error' => 'Missing first or last name'];
                    continue;
                }
                if ($phone === '') {
                    $errors[] = ['row' => $rowNum, 'error' => 'Missing phone'];
                    continue;
                }

                $role = $rolesByCode[$roleKey] ?? $rolesByName[$roleKey] ?? null;
                if (!$role) {
                    $errors[] = ['row' => $rowNum, 'error' => "Unrecognized role \"{$row['role']}\""];
                    continue;
                }

                $department = $deptsByCode[$deptKey] ?? $deptsByName[$deptKey] ?? null;
                if (!$department) {
                    $errors[] = ['row' => $rowNum, 'error' => "Unrecognized department \"{$row['department']}\""];
                    continue;
                }

                $email = trim((string) ($row['email'] ?? '')) ?: null;
                if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = ['row' => $rowNum, 'error' => "Invalid email \"{$email}\""];
                    continue;
                }
                if ($email && User::where('email', $email)->exists()) {
                    $errors[] = ['row' => $rowNum, 'error' => "Email already in use: {$email}"];
                    continue;
                }

                $idNumber = trim((string) ($row['id_number'] ?? '')) ?: null;
                if ($idNumber && User::where('id_number', $idNumber)->exists()) {
                    $errors[] = ['row' => $rowNum, 'error' => "ID number already in use: {$idNumber}"];
                    continue;
                }

                $employmentDate = trim((string) ($row['employment_date'] ?? '')) ?: null;
                if ($employmentDate && !strtotime($employmentDate)) {
                    $errors[] = ['row' => $rowNum, 'error' => "Unrecognized employment date \"{$employmentDate}\""];
                    continue;
                }

                $salaryRaw = preg_replace('/[^0-9.]/', '', (string) ($row['salary'] ?? ''));
                $salary = $salaryRaw !== '' ? (float) $salaryRaw : null;

                $status = in_array($row['status'] ?? null, ['active', 'inactive', 'suspended'], true)
                    ? $row['status'] : 'active';

                $user = User::create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone,
                    'email' => $email,
                    'id_number' => $idNumber,
                    'role_id' => $role->id,
                    'department_id' => $department->id,
                    'employment_date' => $employmentDate ? date('Y-m-d', strtotime($employmentDate)) : null,
                    'salary' => $salary,
                    'status' => $status,
                    'password_hash' => null,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
                $created[] = ['row' => $rowNum, 'id' => $user->id, 'name' => "{$firstName} {$lastName}"];
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Bulk import failed',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => count($created) . ' employee(s) created' .
                (count($errors) ? ', ' . count($errors) . ' row(s) skipped' : ''),
            'data' => [
                'created' => $created,
                'errors' => $errors,
            ],
        ], 201);
    }

    public function show($id)
    {
        try {
            $user = User::with(['role', 'department'])->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            if (!request()->user()->isDirector() && request()->user()->id !== $user->id) {
                $user->makeHidden(self::SENSITIVE_STAFF_FIELDS);
            }

            return response()->json([
                'success' => true,
                'data' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'email' => 'sometimes|nullable|email|unique:users,email,' . $id,
                'phone' => 'sometimes|string|max:20',
                'first_name' => 'sometimes|string|max:50',
                'last_name' => 'sometimes|string|max:50',
                'role_id' => 'sometimes|exists:roles,id',
                'department_id' => 'sometimes|exists:departments,id',
                'id_number' => 'sometimes|nullable|string|max:20|unique:users,id_number,' . $id,
                'staff_number' => 'sometimes|nullable|string|max:20|unique:users,staff_number,' . $id,
                'address' => 'sometimes|nullable|string|max:2000',
                'kra_pin' => 'sometimes|nullable|string|max:20',
                'nssf_number' => 'sometimes|nullable|string|max:20',
                'shif_number' => 'sometimes|nullable|string|max:20',
                'date_of_birth' => 'sometimes|nullable|date',
                'terms_of_employment' => 'sometimes|nullable|string|max:50',
                'employment_date' => 'sometimes|nullable|date',
                'salary' => 'sometimes|nullable|numeric|min:0',
                'house_allowance' => 'sometimes|nullable|numeric|min:0',
                'bank_name' => 'sometimes|nullable|string|max:100',
                'bank_branch' => 'sometimes|nullable|string|max:100',
                'bank_account_number' => 'sometimes|nullable|string|max:50',
                'bank_code' => 'sometimes|nullable|string|max:20',
                'status' => 'sometimes|in:active,inactive,suspended'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user->update(array_merge(
                $request->only([
                    'email', 'phone', 'first_name', 'last_name',
                    'role_id', 'department_id', 'status',
                    'id_number', 'staff_number', 'address', 'kra_pin', 'nssf_number',
                    'shif_number', 'date_of_birth', 'terms_of_employment',
                    'employment_date', 'salary', 'house_allowance',
                    'bank_name', 'bank_branch', 'bank_account_number', 'bank_code',
                ]),
                ['updated_by' => Auth::id()]
            ));

            $user->load(['role', 'department']);

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Prevent deletion of the current user
            if ($user->id === Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete your own account'
                ], 422);
            }

            // Check if user has any related data
            $hasRelatedData = $user->waterTests()->exists() ||
                             $user->batches()->exists() ||
                             $user->orders()->exists() ||
                             $user->attendances()->exists();

            if ($hasRelatedData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete user with related data. Consider deactivating instead.'
                ], 422);
            }

            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function changePassword(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:8|different:current_password',
                'confirm_password' => 'required|same:new_password'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Verify current password
            if (!Hash::check($request->current_password, $user->password_hash)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 422);
            }

            $user->update([
                'password_hash' => Hash::make($request->new_password),
                'updated_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to change password',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:active,inactive,suspended'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Prevent deactivating own account
            if ($user->id === Auth::id() && $request->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot deactivate your own account'
                ], 422);
            }

            $user->update([
                'status' => $request->status,
                'updated_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User status updated successfully',
                'data' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function statistics(Request $request)
    {
        try {
            // User statistics by role
            $usersByRole = User::with(['role'])
                ->selectRaw('role_id, COUNT(*) as count')
                ->groupBy('role_id')
                ->with('role')
                ->get();

            // User statistics by department
            $usersByDepartment = User::with(['department'])
                ->selectRaw('department_id, COUNT(*) as count')
                ->groupBy('department_id')
                ->with('department')
                ->get();

            // User statistics by status
            $usersByStatus = User::selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get();

            // Recent user activity
            $recentUsers = User::with(['role', 'department'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            // User growth over time
            $userGrowth = User::selectRaw('
                DATE_FORMAT(created_at, "%Y-%m") as month,
                COUNT(*) as new_users
            ')->groupBy('month')
              ->orderBy('month')
              ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'users_by_role' => $usersByRole,
                    'users_by_department' => $usersByDepartment,
                    'users_by_status' => $usersByStatus,
                    'recent_users' => $recentUsers,
                    'user_growth' => $userGrowth
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function roles()
    {
        try {
            $roles = Role::orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => $roles
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function departments()
    {
        try {
            $departments = Department::orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => $departments
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch departments',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
