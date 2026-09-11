<?php

declare(strict_types=1);

namespace App\Modules\Landing\Models;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LandingPage extends Model
{
    use Sluggable, SoftDeletes;

    protected $fillable = [
        'user_id',
        'network_id',
        'slug',
        'title',
        'template',
        'content',
        'custom_domain',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'is_published' => 'boolean',
        ];
    }

    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'title',
            ],
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
}
