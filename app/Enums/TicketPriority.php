<?php

namespace App\Enums;

enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    /**
     * Default SLA targets in business minutes, installed for new organizations.
     *
     * @return array{first_response_minutes: int, resolution_minutes: int}
     */
    public function defaultTargets(): array
    {
        return match ($this) {
            self::Low => ['first_response_minutes' => 8 * 60, 'resolution_minutes' => 5 * 8 * 60],
            self::Normal => ['first_response_minutes' => 4 * 60, 'resolution_minutes' => 3 * 8 * 60],
            self::High => ['first_response_minutes' => 60, 'resolution_minutes' => 8 * 60],
            self::Urgent => ['first_response_minutes' => 15, 'resolution_minutes' => 4 * 60],
        };
    }
}
