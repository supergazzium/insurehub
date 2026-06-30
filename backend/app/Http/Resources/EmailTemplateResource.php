<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmailTemplate
 */
class EmailTemplateResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'label' => $this->label,
            'desc' => $this->description ?? '',
            'icon' => $this->icon ?? '',
            'department' => $this->department,
            'subject' => $this->subject,
            'body' => $this->body,
            'isBuiltIn' => (bool) $this->is_built_in,
            'active' => (bool) $this->active,
        ];
    }
}
