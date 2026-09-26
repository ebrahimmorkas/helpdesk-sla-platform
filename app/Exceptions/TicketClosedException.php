<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class TicketClosedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This ticket is closed and can no longer be changed. Please open a new ticket.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
