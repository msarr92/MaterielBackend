<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;


#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'matricule',
        'username',
        'nom',
        'prenom',
        'password',
        'role',
        'direction_id',
        'site_id',
        'direction_libelle',
        'site_libelle',
        'actif'
    ];


    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

     // JWT REQUIRED METHODS
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function direction()
    {
        return $this->belongsTo(Direction::class );
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function attributions()
    {
        return $this->hasMany(Attribution::class);
    }

    public function mouvements()
    {
        return $this->hasMany(MouvementMateriel::class );
    }



}
