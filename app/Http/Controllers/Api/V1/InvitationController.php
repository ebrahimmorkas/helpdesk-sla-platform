<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class InvitationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $invitations = Invitation::whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->paginate($this->perPage($request))
            ->through(fn (Invitation $i) => $this->present($i));

        return response()->json($invitations);
    }

    /**
     * Admins may invite anyone; agents may only invite customers.
     */
    public function store(Request $request, InvitationService $invitations): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->isStaff(), 403);

        $allowedRoles = $actor->isAdmin() ? Role::cases() : [Role::Customer];

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)->only($allowedRoles)],
        ]);

        $invitation = $invitations->invite($actor, $data['email'], $data['name'], Role::from($data['role']));

        return response()->json(['data' => $this->present($invitation)], 201);
    }

    public function destroy(Request $request, Invitation $invitation): Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        $invitation->delete();

        return response()->noContent();
    }

    private function present(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'name' => $invitation->name,
            'role' => $invitation->role,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
        ];
    }
}
