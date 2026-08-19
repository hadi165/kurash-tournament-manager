<?php

namespace App\Models;

use Database\Factories\AgeCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgeCategory extends Model
{
    /** @use HasFactory<AgeCategoryFactory> */
    use HasFactory;

    protected $fillable = ['championship_id', 'name', 'sort_order'];

    /** @return BelongsTo<Championship, $this> */
    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    /** @return HasMany<WeightCategory, $this> */
    public function weightCategories(): HasMany
    {
        return $this->hasMany(WeightCategory::class)->orderBy('sort_order');
    }

    /** @return HasMany<Athlete, $this> */
    public function athletes(): HasMany
    {
        return $this->hasMany(Athlete::class);
    }
}
