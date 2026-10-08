<?php

namespace App\Models\backend;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
     protected $table = 'countries';
    protected $fillable = [
        'name',
      
    ];
}
