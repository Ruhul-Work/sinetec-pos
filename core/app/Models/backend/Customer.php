<?php
namespace App\Models\backend;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table   = 'customers';
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

}
