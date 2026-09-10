<?php

namespace App\Events;

use App\Models\SearchJob;
use Illuminate\Foundation\Events\Dispatchable;

class SearchJobFinished
{
    use Dispatchable;

    public function __construct(public SearchJob $searchJob) {}
}
