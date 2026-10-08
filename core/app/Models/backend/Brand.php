<?php

namespace App\Models\backend;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use SoftDeletes;

    protected $table = 'brands';
    protected $fillable = [
        'name',
        'slug',
        'is_active',
        'image',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'meta_image',

    ];
}
