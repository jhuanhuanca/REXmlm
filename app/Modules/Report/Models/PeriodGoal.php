<?php

declare(strict_types=1);

namespace App\Modules\Report\Models;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\Organization\Models\Organization;
use App\Shared\Enums\PeriodGoalMetric;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodGoal extends Model
{
    protected $fillable = [
        'user_id',
        'network_id',
        'organization_id',
        'period',
        'metric',
        'plane',
        'target',
    ];

    protected function casts(): array
    {
        return [
            'metric' => PeriodGoalMetric::class,
            'target' => 'decimal:4',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
