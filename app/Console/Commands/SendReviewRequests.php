<?php

namespace App\Console\Commands;

use App\Services\Reviews\ReviewRequests;
use Illuminate\Console\Command;

class SendReviewRequests extends Command
{
    protected $signature = 'reviews:send-requests';

    protected $description = 'Send the review-request emails that are due';

    public function handle(ReviewRequests $requests): int
    {
        $this->info($requests->sendDue().' review request email(s) queued.');

        return self::SUCCESS;
    }
}
