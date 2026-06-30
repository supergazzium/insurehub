<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailMessage extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'zoho_payload' => 'array',
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MailThread::class, 'mail_thread_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MailAttachment::class);
    }
}
