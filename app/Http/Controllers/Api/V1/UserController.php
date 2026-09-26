<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $request->validate([
            'role' => ['nullable', Rule::enum(Role::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $users = User::inCurrentOrganization()
            ->when($request->query('role'), fn ($q, $role) => $q->where('role', $role))
            ->when($request->query('search'), fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user);
    }

    public function update(Request $request, User $user): UserResource
    {
        Gate::authorize('update', $user);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', Rule::enum(Role::class)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($request->user()->is($user) && (($data['is_active'] ?? true) === false || ($data['role'] ?? 'admin') !== 'admin')) {
            throw ValidationException::withMessages(['user' => 'You cannot deactivate or demote your own account.']);
        }

        DB::transaction(function () use ($user, $data) {
            $user->update($data);

            if ($user->wasChanged('is_active') && ! $user->is_active) {
                $user->tokens()->delete();
            }
        });

        return new UserResource($user);
    }
}
