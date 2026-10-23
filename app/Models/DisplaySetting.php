<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisplaySetting extends Model
{
    protected $fillable = ['audio_department_id', 'audio_source_id'];

    protected function casts(): array
    {
        return ['audio_department_id' => 'integer'];
    }
}
