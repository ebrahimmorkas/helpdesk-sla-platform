<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

/**
 * In-app notifications of the authenticated user. Every query goes through
 * $request->user()->notifications(), so users can only see or mark their own.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['unread' => ['nullable', 'boolean']]);

        $notifications = $request->user()->notifications()
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'data' => $n->data,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at->toIso8601String(),
            ]);

        return response()->json($notifications);
    }

    public function markAsRead(Request $request, string $id): Response
    {
        $request->user()->notifications()->findOrFail($id)->markAsRead();

        return response()->noContent();
    }
}
