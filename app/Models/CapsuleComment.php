<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CapsuleComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'capsule_id',
        'user_id',
        'comment',
    ];

    public function capsule()
    {
        return $this->belongsTo(Capsule::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
