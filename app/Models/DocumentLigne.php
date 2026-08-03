<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentLigne extends Model
{
    protected $fillable = [
        'document_id',
        'materiel_id',
        'user_id',
        'direction'
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function materiel()
    {
        return $this->belongsTo(Materiel::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
