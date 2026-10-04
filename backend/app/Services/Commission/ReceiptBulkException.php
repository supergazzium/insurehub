<?php

declare(strict_types=1);

namespace App\Services\Commission;

use RuntimeException;

final class ReceiptBulkException extends RuntimeException
{
    public function __construct(public readonly array $rowErrors)
    {
        parent::__construct('มีบางรายการบันทึกไม่ได้ ระบบยังไม่บันทึกรายการใดในชุดนี้');
    }
}
