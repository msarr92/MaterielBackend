<?php

namespace App\Imports;

use App\Models\Beneficiaire;
use App\Models\Direction;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class BeneficiairesImport implements SkipsOnError, ToModel, WithChunkReading, WithHeadingRow, WithValidation
{
    use SkipsErrors;

    protected $imported = 0;

    protected $ignored = 0;

    protected $errors = [];

    public function model(array $row)
    {
        // Nettoyer le téléphone
        $telephone = $row['telephone'] ?? $row['Tel'] ?? $row['Téléphone'] ?? null;
        if ($telephone) {
            $telephone = preg_replace('/[^0-9]/', '', $telephone);
            $telephone = substr($telephone, -9);
        }

        // Chercher la direction par nom ou ID
        $directionName = $row['direction'] ?? $row['Direction'] ?? null;
        $directionId = $row['direction_id'] ?? null;

        if ($directionName && ! $directionId) {
            $direction = Direction::firstOrCreate(['nom' => $directionName]);
            $directionId = $direction->id;
        }

        // Vérifier si le matricule existe déjà
        $matricule = $row['matricule'] ?? $row['Matricule'] ?? null;

        if (! $matricule) {
            $this->ignored++;

            return null;
        }

        if (Beneficiaire::where('matricule', $matricule)->exists()) {
            $this->ignored++;
            $this->errors[] = "Matricule $matricule existe déjà";

            return null;
        }

        // Vérifier le cache
        $cacheKey = 'beneficiaire:'.$matricule;
        if (Cache::has($cacheKey)) {
            $this->ignored++;

            return null;
        }

        Cache::put($cacheKey, $matricule, now()->addHour());
        $this->imported++;

        return new Beneficiaire([
            'matricule' => $matricule,
            'nom' => $row['nom'] ?? $row['Nom'] ?? null,
            'prenom' => $row['prenom'] ?? $row['Prénom'] ?? null,
            'telephone' => $telephone,
            'fonction' => $row['fonction'] ?? $row['Fonction'] ?? null,
            'direction_id' => $directionId,
        ]);
    }

    public function rules(): array
    {
        return [
            '*.matricule' => 'required',
            '*.nom' => 'required',
            '*.prenom' => 'required',
        ];
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function getImportedCount()
    {
        return $this->imported;
    }

    public function getIgnoredCount()
    {
        return $this->ignored;
    }

    public function getErrors()
    {
        return $this->errors;
    }
}
