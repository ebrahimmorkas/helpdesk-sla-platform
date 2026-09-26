<?php

namespace App\Enums;

enum ActivityType: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case PriorityChanged = 'priority_changed';
    case Assigned = 'assigned';
    case SlaBreached = 'sla_breached';
}
