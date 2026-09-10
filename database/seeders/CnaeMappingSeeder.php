<?php

namespace Database\Seeders;

use App\Models\CnaeMapping;
use Illuminate\Database\Seeder;

/**
 * Initial keyword → CNAE mapping. Complements the match by CNAE description.
 * 7-digit codes (subclass), as in the Receita dump.
 */
class CnaeMappingSeeder extends Seeder
{
    public const array MAPPINGS = [
        'clínica de estética' => ['9602502', '9602501', '8630503'],
        'estética' => ['9602502', '9602501'],
        'day spa' => ['9609206', '9602502'],
        'spa' => ['9609206'],
        'salão de beleza' => ['9602501'],
        'cabeleireiro' => ['9602501'],
        'barbearia' => ['9602501'],
        'manicure' => ['9602501'],
        'clínica odontológica' => ['8630504'],
        'dentista' => ['8630504'],
        'clínica médica' => ['8630501', '8630502', '8630503'],
        'consultório médico' => ['8630503'],
        'fisioterapia' => ['8650004'],
        'psicólogo' => ['8650003'],
        'nutricionista' => ['8650002'],
        'academia' => ['9313100'],
        'pilates' => ['9313100'],
        'crossfit' => ['9313100'],
        'pet shop' => ['4789004', '9609208'],
        'clínica veterinária' => ['7500100'],
        'restaurante' => ['5611201'],
        'lanchonete' => ['5611203'],
        'bar' => ['5611204'],
        'cafeteria' => ['5611203'],
        'padaria' => ['1091102', '4721102'],
        'pizzaria' => ['5611201', '5620104'],
        'hotel' => ['5510801'],
        'pousada' => ['5510802'],
        'escola de idiomas' => ['8593700'],
        'escola infantil' => ['8511200'],
        'autoescola' => ['8592999', '8599604'],
        'oficina mecânica' => ['4520001'],
        'lava rápido' => ['4520005'],
        'imobiliária' => ['6821801', '6821802'],
        'escritório de contabilidade' => ['6920601'],
        'contador' => ['6920601'],
        'escritório de advocacia' => ['6911701'],
        'advogado' => ['6911701'],
        'agência de marketing' => ['7311400', '7319002'],
        'agência de viagens' => ['7911200'],
        'loja de roupas' => ['4781400'],
        'ótica' => ['4774100'],
        'farmácia' => ['4771701'],
        'mercado' => ['4711302', '4712100'],
        'floricultura' => ['4789002'],
        'marcenaria' => ['3101200'],
        'construtora' => ['4120400'],
        'eletricista' => ['4321500'],
        'serralheria' => ['2542000'],
        'gráfica' => ['1813001', '1813099'],
        'desenvolvimento de software' => ['6201501', '6202300'],
        'agência digital' => ['6201501', '7311400'],
        'consultoria' => ['7020400'],
        'coworking' => ['6810202', '8211300'],
        'clínica de fisioterapia' => ['8650004'],
        'clínica de psicologia' => ['8650003'],
        'studio de tatuagem' => ['9609299'],
        'lavanderia' => ['9601701'],
    ];

    public function run(): void
    {
        foreach (self::MAPPINGS as $keyword => $codes) {
            foreach ($codes as $code) {
                CnaeMapping::firstOrCreate(['keyword' => mb_strtolower($keyword), 'cnae_code' => $code]);
            }
        }
    }
}
