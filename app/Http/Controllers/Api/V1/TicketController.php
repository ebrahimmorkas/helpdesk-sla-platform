<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OpenTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    private const LIST_RELATIONS = ['requester:id,name,email', 'assignee:id,name'];

    public function __construct(private readonly TicketService $tickets) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(TicketStatus::class)],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'assignee' => ['nullable', 'string', 'regex:/^(me|none|\d+)$/'],
            'requester_id' => ['nullable', 'integer'],
        ]);

        $sorts = ['created_at', 'updated_at', 'resolution_due_at', 'first_response_due_at', 'number'];
        [$column, $direction] = $this->sort($request, $sorts, '-created_at');

        $tickets = Ticket::query()
            ->visibleTo($user)
            ->with(self::LIST_RELATIONS)
            ->when($request->query('status'), fn ($q, $statuses) => $q->whereIn('status', $statuses))
            ->when($request->query('priority'), fn ($q, $priority) => $q->where('priority', $priority))
            ->when($user->isStaff() && $request->query('requester_id'), fn ($q) => $q->where('requester_id', $request->integer('requester_id')))
            ->when($user->isStaff() ? $request->query('assignee') : null, fn ($q, $assignee) => match ($assignee) {
                'me' => $q->where('assignee_id', $user->id),
                'none' => $q->whereNull('assignee_id'),
                default => $q->where('assignee_id', (int) $assignee),
            })
            ->orderBy($column, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return TicketResource::collection($tickets);
    }

    public function store(OpenTicketRequest $request): JsonResponse
    {
        $ticket = $this->tickets->open($request->validated(), $request->user());

        return (new TicketResource($ticket->load(self::LIST_RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(Request $request, Ticket $ticket): TicketResource
    {
        Gate::authorize('view', $ticket);

        return new TicketResource($ticket->load([
            ...self::LIST_RELATIONS,
            'messages' => fn ($q) => $q->visibleTo($request->user())->with(['author:id,name,role', 'attachments'])->orderBy('id'),
        ]));
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket): TicketResource
    {
        $ticket = $this->tickets->update($ticket, $request->validated(), $request->user());

        return new TicketResource($ticket->load(self::LIST_RELATIONS));
    }

    public function activity(Ticket $ticket): JsonResponse
    {
        Gate::authorize('viewInternals', $ticket);

        return response()->json([
            'data' => $ticket->activities()->with('actor:id,name')->orderBy('id')->get()->map(fn ($activity) => [
                'type' => $activity->type,
                'data' => $activity->data,
                'actor' => $activity->actor?->only(['id', 'name']),
                'created_at' => $activity->created_at->toIso8601String(),
            ]),
        ]);
    }
}
