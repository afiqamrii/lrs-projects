<?php

namespace App\Support;

class ExtractionProblem extends \RuntimeException
{
    public function __construct(public string $reason, string $message, public bool $unavailable = false)
    {
        parent::__construct($message);
    }
}
