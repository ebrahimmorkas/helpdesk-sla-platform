<?php

/*
 * Helper for ConcurrentTicketNumberTest: boots the application in its own PHP
 * process and opens one ticket as the given customer, printing its number.
 *
 * Usage: php tests/Support/open-ticket.php <customer-id>
 */

use App\Models\User;
use App\Services\TicketService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$customer = User::findOrFail($argv[1]);
$app->make(CurrentOrganization::class)->set($customer->organization_id);

echo $app->make(TicketService::class)
    ->open(['subject' => 'Parallel ticket', 'description' => 'Opened concurrently'], $customer)
    ->number;
