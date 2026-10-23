<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use SoftDeletes;

    protected $fillable = ['reference', 'display_name', 'details_complete', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['details_complete' => 'boolean'];
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
