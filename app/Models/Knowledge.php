<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Knowledge extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'team_id',
        'name',
        'type',
        'text',
        'qa',
        'filepath',
        'filename',
        'size',
        'status',
    ];

    protected $casts = [
        'qa' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('order', function ($builder) {
            $builder->orderBy('created_at', 'desc');
        });
    }

    public function vectors(): HasMany
    {
        return $this->hasMany(KnowledgeVector::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function bots()
    {
        return $this->morphToMany(Bot::class, 'connectable', 'bot_connections');
    }
}
