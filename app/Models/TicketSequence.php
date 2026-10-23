<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketSequence extends Model
{
    protected $fillable = ['id', 'next_number', 'prefix'];
}
