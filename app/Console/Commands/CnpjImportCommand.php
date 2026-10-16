<?php

namespace App\Console\Commands;

use App\Domains\LeadSources\Drivers\CnpjBr\ReceitaCsvReader;
use App\Models\Cnae;
use App\Models\CnpjEstablishment;
use App\Models\CnpjMunicipality;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports the CSVs from the Receita Federal public dump (https://dadosabertos.rfb.gov.br/CNPJ/).
 *
 *   cnpj:import cnaes Cnaes.csv
 *   cnpj:import municipios Municipios.csv
 *   cnpj:import estabelecimentos Estabelecimentos0.csv   (repeat for each part; active only by default)
 *   cnpj:import empresas Empresas0.csv                   (fills legal name and size on already imported rows)
 */
class CnpjImportCommand extends Command
{
    protected $signature = 'cnpj:import
        {type : cnaes|municipios|estabelecimentos|empresas}
        {path : path to the unzipped CSV}
        {--all : include inactive establishments}
        {--chunk=2000}';

    protected $description = 'Imports files from the Brazilian Receita Federal CNPJ dump';

    public function handle(ReceitaCsvReader $reader): int
    {
        $type = $this->argument('type');
        $path = $this->argument('path');

        if (! is_string($type) || ! is_string($path)) {
            $this->error('Pass the file type and the path to the CSV.');

            return self::FAILURE;
        }

        $chunk = max(100, (int) $this->option('chunk'));

        $count = match ($type) {
            'cnaes' => $this->importCnaes($reader, $path, $chunk),
            'municipios' => $this->importMunicipalities($reader, $path, $chunk),
            'estabelecimentos' => $this->importEstablishments($reader, $path, $chunk),
            'empresas' => $this->importCompanies($reader, $path, $chunk),
            default => null,
        };

        if ($count === null) {
            $this->error("Invalid type: {$type}. Use cnaes, municipios, estabelecimentos or empresas.");

            return self::INVALID;
        }

        $this->info(number_format($count)." records processed from {$type}.");

        return self::SUCCESS;
    }

    private function importCnaes(ReceitaCsvReader $reader, string $path, int $chunk): int
    {
        return $this->upsertRows($reader, $path, $chunk, function (array $row) {
            return isset($row[0], $row[1]) && $row[0] !== ''
                ? ['code' => $row[0], 'description' => mb_substr($row[1], 0, 255)]
                : null;
        }, fn (array $rows) => Cnae::upsert($rows, ['code'], ['description']));
    }

    private function importMunicipalities(ReceitaCsvReader $reader, string $path, int $chunk): int
    {
        return $this->upsertRows($reader, $path, $chunk, function (array $row) {
            return isset($row[0], $row[1]) && $row[0] !== ''
                ? ['code' => $row[0], 'name' => mb_convert_case(mb_strtolower($row[1]), MB_CASE_TITLE)]
                : null;
        }, fn (array $rows) => CnpjMunicipality::upsert($rows, ['code'], ['name']));
    }

    private function importEstablishments(ReceitaCsvReader $reader, string $path, int $chunk): int
    {
        $onlyActive = ! $this->option('all');

        return $this->upsertRows($reader, $path, $chunk, function (array $row) use ($onlyActive) {
            if (count($row) < 28) {
                return null;
            }

            $situacao = str_pad($row[5], 2, '0', STR_PAD_LEFT);

            if ($onlyActive && $situacao !== CnpjEstablishment::ACTIVE) {
                return null;
            }

            $ddd = fn (string $d) => preg_replace('/\D+/', '', $d) ?? '';
            $phone = fn (string $ddd, string $num) => ($ddd.$num) !== '' ? mb_substr($ddd.$num, 0, 16) : null;

            return [
                'cnpj' => $row[0].$row[1].$row[2],
                'cnpj_basico' => $row[0],
                'is_headquarters' => $row[3] === '1',
                'nome_fantasia' => $row[4] !== '' ? mb_substr($row[4], 0, 255) : null,
                'situacao' => $situacao,
                'data_inicio' => preg_match('/^\d{8}$/', $row[10]) && $row[10] !== '00000000' ? substr($row[10], 0, 4).'-'.substr($row[10], 4, 2).'-'.substr($row[10], 6, 2) : null,
                'cnae_principal' => $row[11],
                'cnae_secundaria' => $row[12] !== '' ? $row[12] : null,
                'uf' => $row[19],
                'municipio_code' => $row[20],
                'bairro' => $row[17] !== '' ? mb_substr($row[17], 0, 255) : null,
                'cep' => $row[18] !== '' ? $ddd($row[18]) : null,
                'telefone1' => $phone($ddd($row[21]), $ddd($row[22])),
                'telefone2' => $phone($ddd($row[23]), $ddd($row[24])),
                'email' => $row[27] !== '' ? mb_strtolower(mb_substr($row[27], 0, 255)) : null,
            ];
        }, fn (array $rows) => CnpjEstablishment::upsert($rows, ['cnpj'], [
            'is_headquarters', 'nome_fantasia', 'situacao', 'data_inicio', 'cnae_principal', 'cnae_secundaria',
            'uf', 'municipio_code', 'bairro', 'cep', 'telefone1', 'telefone2', 'email',
        ]));
    }

    /** Empresas: only updates establishments already imported (legal name and size). */
    private function importCompanies(ReceitaCsvReader $reader, string $path, int $chunk): int
    {
        return $this->upsertRows($reader, $path, $chunk, function (array $row) {
            return count($row) >= 6 && $row[0] !== ''
                ? ['cnpj_basico' => $row[0], 'razao_social' => mb_substr($row[1], 0, 255), 'porte' => $row[5] !== '' ? str_pad($row[5], 2, '0', STR_PAD_LEFT) : null]
                : null;
        }, function (array $rows) {
            $values = implode(',', array_fill(0, count($rows), '(?, ?, ?)'));
            $bindings = [];

            foreach ($rows as $r) {
                array_push($bindings, $r['cnpj_basico'], $r['razao_social'], $r['porte']);
            }

            DB::statement(
                "UPDATE cnpj_establishments e SET razao_social = v.razao_social, porte = v.porte
                 FROM (VALUES {$values}) AS v(cnpj_basico, razao_social, porte)
                 WHERE e.cnpj_basico = v.cnpj_basico",
                $bindings,
            );
        });
    }

    /**
     * @param  callable(list<string>): (array<string, mixed>|null)  $map
     * @param  callable(list<array<string, mixed>>): mixed  $flush
     */
    private function upsertRows(ReceitaCsvReader $reader, string $path, int $chunk, callable $map, callable $flush): int
    {
        $buffer = [];
        $count = 0;
        $bar = $this->output->createProgressBar();
        $bar->setFormat(' %current% rows [%bar%] %elapsed% · %memory%');

        foreach ($reader->rows($path) as $row) {
            $bar->advance();
            $mapped = $map($row);

            if ($mapped === null) {
                continue;
            }

            $buffer[$mapped['cnpj'] ?? $mapped['code'] ?? $mapped['cnpj_basico'] ?? $count] = $mapped;

            if (count($buffer) >= $chunk) {
                $flush(array_values($buffer));
                $count += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $flush(array_values($buffer));
            $count += count($buffer);
        }

        $bar->finish();
        $this->newLine();

        return $count;
    }
}
