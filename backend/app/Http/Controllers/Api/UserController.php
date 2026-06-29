<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with(['roles', 'hospitals'])
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->q.'%')
                ->orWhere('email', 'like', '%'.$request->q.'%'))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return UserResource::collection($users);
    }

    public function show(User $user)
    {
        $this->authorize('view', $user);

        return new UserResource($user->load(['roles', 'permissions', 'hospitals']));
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'email'      => ['required', 'email', 'unique:users,email'],
            'password'   => ['required', 'string', 'min:8'],
            'phone'      => ['nullable', 'string', 'max:50'],
            'region'     => ['nullable', 'string', 'max:100'],
            'staff_type' => ['nullable', 'string', 'max:50'],
            'role'       => ['required', Rule::exists('roles', 'name')],
            'is_active'  => ['boolean'],
        ]);

        $user = User::create([
            ...collect($data)->except(['role', 'password'])->all(),
            'password' => Hash::make($data['password']),
        ]);
        $user->assignRole($data['role']);

        return (new UserResource($user->load('roles')))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'name'       => ['sometimes', 'string', 'max:255'],
            'email'      => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password'   => ['nullable', 'string', 'min:8'],
            'phone'      => ['nullable', 'string', 'max:50'],
            'region'     => ['nullable', 'string', 'max:100'],
            'staff_type' => ['nullable', 'string', 'max:50'],
            'is_active'  => ['boolean'],
            'role'       => ['sometimes', Rule::exists('roles', 'name')],
        ]);

        $user->update(collect($data)->except(['role', 'password'])
            ->when(! empty($data['password']), fn ($c) => $c->put('password', Hash::make($data['password'])))
            ->all());

        if (isset($data['role'])) {
            $this->authorize('manageRoles', User::class);
            $user->syncRoles([$data['role']]);
        }

        return new UserResource($user->fresh(['roles', 'hospitals']));
    }

    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'User deactivated.']);
    }

    /** Assign/sync hospitals (rep/runner) to a user. */
    public function syncHospitals(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'assignments'           => ['required', 'array'],
            'assignments.*.hospital_id' => ['required', 'exists:hospitals,id'],
            'assignments.*.role'        => ['nullable', 'string'],
        ]);

        $sync = [];
        foreach ($data['assignments'] as $a) {
            $sync[$a['hospital_id']] = ['role' => $a['role'] ?? 'rep'];
        }
        $user->hospitals()->sync($sync);

        return new UserResource($user->fresh('hospitals'));
    }

    /** Available roles for the user-management UI. */
    public function roles()
    {
        $this->authorize('manageRoles', User::class);

        return response()->json(Role::with('permissions:id,name')->get(['id', 'name']));
    }
}
