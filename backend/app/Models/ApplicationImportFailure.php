<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationImportFailure extends Model
{
    protected $table = 'applications_import_failures';

    protected $guarded = ['id'];

    protected $casts = [
        'imported_at' => 'datetime',
        'resolved' => 'boolean',
        'raw_json' => 'array',
    ];
}
