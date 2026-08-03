<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'numero_document',
        'type_document',
        'attribution_id',
        'document_id',
        'fichier_pdf',
        'fichier_scan',
        'date_generation',
        'date_televersement',
        'created_by',
        'observation',
    ];

    public function attributions()
    {
        return $this->hasMany(Attribution::class, 'document_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lignes()
    {
        return $this->hasMany(DocumentLigne::class);
    }
}
