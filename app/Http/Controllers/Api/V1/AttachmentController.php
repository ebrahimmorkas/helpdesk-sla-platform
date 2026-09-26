<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Route binding is tenant-scoped (another tenant's attachment is a 404).
     * Access then follows the ticket, and attachments on internal notes are
     * staff-only. Files are always sent as downloads with nosniff so a browser
     * never renders uploaded content inline.
     */
    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        $message = $attachment->message()->with('ticket')->firstOrFail();

        Gate::authorize('view', $message->ticket);
        abort_if($message->is_internal && ! $request->user()->isStaff(), 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
