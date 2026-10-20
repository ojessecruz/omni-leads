<?php

namespace App\Http\Controllers\Api;

use App\Actions\Leads\ChangeLeadStatus;
use App\Actions\WhatsApp\QueueFirstContact;
use App\Domains\Outbound\WhatsAppTemplate;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Leads of the token's user's projects, for CRMs and bots. */
class LeadController extends Controller
{
    /** Latest updated first; updated_since lets a client sync only what changed. */
    public function index(Request $request): AnonymousResourceCollection
    {
        // ?status=qualified,contacted and ?status[]=qualified both work.
        if (is_string($request->query('status'))) {
            $request->merge(['status' => array_values(array_filter(explode(',', $request->query('status'))))]);
        }

        $request->validate([
            'per_page' => ['integer', 'between:1,100'],
            'project_id' => ['integer'],
            'icp_id' => ['integer'],
            'status' => ['array'],
            'status.*' => [Rule::enum(LeadStatus::class)],
            'min_score' => ['integer', 'between:0,100'],
            'updated_since' => ['date'],
        ]);

        $leads = $this->query($request)
            ->when($request->filled('project_id'), fn (Builder $q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('icp_id'), fn (Builder $q) => $q->inIcp($request->integer('icp_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->whereIn('status', (array) $request->input('status')))
            ->when($request->filled('min_score'), fn (Builder $q) => $q->where('score', '>=', $request->integer('min_score')))
            ->when($request->filled('updated_since'), fn (Builder $q) => $q->where('updated_at', '>=', Carbon::parse((string) $request->string('updated_since'))))
            ->with(LeadResource::WITH)
            ->latest('updated_at')
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return LeadResource::collection($leads);
    }

    public function show(Request $request, int $lead): LeadResource
    {
        return new LeadResource($this->find($request, $lead));
    }

    /** Changes the status; contacted, discarded and the others fire the same webhooks as the app. */
    public function update(Request $request, int $lead, ChangeLeadStatus $change): LeadResource
    {
        $model = $this->find($request, $lead);
        $data = $request->validate(['status' => ['required', Rule::enum(LeadStatus::class)]]);

        $change($model, LeadStatus::from($data['status']));

        return new LeadResource($model->refresh()->load(LeadResource::WITH));
    }

    /**
     * Queues the first WhatsApp message, paced like the app (gap, hourly and daily caps,
     * business hours). Without text, the project's template is used.
     */
    public function whatsapp(Request $request, int $lead, QueueFirstContact $queue): JsonResponse
    {
        // The page eager load trims the project; sending needs its template and owner.
        $model = $this->find($request, $lead)->load('project.user');
        $data = $request->validate(['text' => ['nullable', 'string', 'max:2000']]);

        $text = filled($data['text'] ?? null)
            ? trim($data['text'])
            : WhatsAppTemplate::render($model->project->whatsapp_template, $model);

        try {
            $at = $queue($model, $text);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['text' => $e->getMessage()]);
        }

        return response()->json(['status' => 'scheduled', 'scheduled_for' => $at->toIso8601String()], 202);
    }

    /** @return Builder<Lead> */
    private function query(Request $request): Builder
    {
        return Lead::query()->whereHas('project', fn (Builder $q) => $q->where('user_id', $request->user()->id));
    }

    private function find(Request $request, int $id): Lead
    {
        return $this->query($request)->with(LeadResource::WITH)->findOrFail($id);
    }
}
