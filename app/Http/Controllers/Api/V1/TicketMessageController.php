<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TicketMessageResource;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TicketMessageController extends Controller
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'txt', 'csv', 'log', 'zip', 'docx', 'xlsx'];

    public function store(Request $request, Ticket $ticket, TicketService $tickets): JsonResponse
    {
        Gate::authorize('reply', $ticket);
        $staff = $request->user()->isStaff();

        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'is_internal' => $staff ? ['sometimes', 'boolean'] : ['prohibited'],
            'status' => $staff
                ? ['sometimes', Rule::enum(TicketStatus::class)->only([TicketStatus::Open, TicketStatus::Pending, TicketStatus::Resolved])]
                : ['prohibited'],
            // "mimes" checks the detected content type, not just the extension.
            // HTML, SVG and scripts are not accepted.
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:'.implode(',', self::ALLOWED_EXTENSIONS)],
        ]);

        $message = $tickets->reply(
            $ticket,
            $request->user(),
            $data['body'],
            (bool) ($data['is_internal'] ?? false),
            isset($data['status']) ? TicketStatus::from($data['status']) : null,
            $request->file('attachments', []),
        );

        return (new TicketMessageResource($message->load(['author:id,name,role', 'attachments'])))
            ->response()
            ->setStatusCode(201);
    }
}
