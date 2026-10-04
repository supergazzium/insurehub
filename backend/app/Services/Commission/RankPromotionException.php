<?php

declare(strict_types=1);

namespace App\Services\Commission;

use RuntimeException;

class RankPromotionException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
