<?php

namespace App\Models;

use App\Enums\IcpDraftMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property int $step
 * @property IcpDraftMode $mode
 * @property array<string, mixed> $draft
 */
#[Fillable(['project_id', 'user_id', 'step', 'mode', 'draft'])]
class IcpDraft extends Model
{
    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'mode' => IcpDraftMode::class,
            'draft' => 'array',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
