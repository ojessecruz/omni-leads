<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $cnpj
 * @property string $cnpj_basico
 * @property bool $is_headquarters
 * @property string|null $razao_social
 * @property string|null $nome_fantasia
 * @property string $situacao
 * @property Carbon|null $data_inicio
 * @property string $cnae_principal
 * @property string|null $cnae_secundaria
 * @property string $uf
 * @property string $municipio_code
 * @property string|null $bairro
 * @property string|null $cep
 * @property string|null $telefone1
 * @property string|null $telefone2
 * @property string|null $email
 * @property string|null $porte
 * @property-read CnpjMunicipality|null $municipality
 */
#[Fillable([
    'cnpj', 'cnpj_basico', 'is_headquarters', 'razao_social', 'nome_fantasia', 'situacao', 'data_inicio',
    'cnae_principal', 'cnae_secundaria', 'uf', 'municipio_code', 'bairro', 'cep', 'telefone1', 'telefone2', 'email', 'porte',
])]
class CnpjEstablishment extends Model
{
    public const string ACTIVE = '02';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'cnpj';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'is_headquarters' => 'boolean',
            'data_inicio' => 'date',
        ];
    }

    /** @return BelongsTo<CnpjMunicipality, $this> */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(CnpjMunicipality::class, 'municipio_code', 'code');
    }
}
