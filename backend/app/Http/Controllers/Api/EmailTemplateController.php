<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\EmailTemplateRequest;
use App\Http\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmailTemplateController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $this->scopeTenant(EmailTemplate::query(), $request);
        if ($department = $request->input('department')) {
            $q->where('department', $department);
        }
        return EmailTemplateResource::collection($q->orderBy('label')->paginate($this->perPage($request)));
    }

    public function store(EmailTemplateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tpl = EmailTemplate::create([
            'tenant_id' => $this->tenantId($request),
            'label' => $data['label'],
            'description' => $data['desc'] ?? null,
            'icon' => $data['icon'] ?? null,
            'department' => $data['department'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'is_built_in' => false,
            'active' => (bool) ($data['active'] ?? true),
        ]);
        return (new EmailTemplateResource($tpl))->response()->setStatusCode(201);
    }

    public function show(Request $request, EmailTemplate $template): EmailTemplateResource
    {
        $this->authorizeTenant($request, $template);
        return new EmailTemplateResource($template);
    }

    public function update(EmailTemplateRequest $request, EmailTemplate $template): EmailTemplateResource
    {
        $this->authorizeTenant($request, $template);
        $data = $request->validated();
        $updates = array_filter([
            'label' => $data['label'] ?? null,
            'description' => array_key_exists('desc', $data) ? $data['desc'] : null,
            'icon' => array_key_exists('icon', $data) ? $data['icon'] : null,
            'department' => $data['department'] ?? null,
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'] ?? null,
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : null,
        ], static fn ($v) => $v !== null);
        $template->update($updates);
        return new EmailTemplateResource($template->fresh());
    }

    public function destroy(Request $request, EmailTemplate $template): JsonResponse
    {
        $this->authorizeTenant($request, $template);
        if ($template->is_built_in) {
            abort(403, 'Cannot delete a built-in template.');
        }
        $template->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    private function authorizeTenant(Request $request, EmailTemplate $t): void
    {
        if ((int) $t->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
