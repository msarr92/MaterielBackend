<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attribution extends Model
{
    protected $fillable = [
        'materiel_id',
        'user_id',
        'direction_id',
        'site_id',
        'date_debut',
        'document_id',
        'date_fin',
        'statut',
        'type_action',
        'previous_attribution_id',
        'created_by',
    ];

    public function materiel()
    {
        return $this->belongsTo(Materiel::class, 'materiel_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function direction()
    {
        return $this->belongsTo(Direction::class, 'direction_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function previous()
    {
        return $this->belongsTo(Attribution::class, 'previous_attribution_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function document()
{
    return $this->belongsTo(Document::class, 'document_id');
}

}
