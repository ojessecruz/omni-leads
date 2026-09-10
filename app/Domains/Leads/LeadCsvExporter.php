<?php

namespace App\Domains\Leads;

use App\Models\Lead;
use App\Support\Countries;
use Illuminate\Database\Eloquent\Builder;

class LeadCsvExporter
{
    public const array COLUMNS = [
        'id', 'company', 'name', 'website', 'email', 'phone', 'whatsapp', 'country', 'city', 'tax_id',
        'score', 'status', 'icp', 'sources', 'discovered_at',
    ];

    /**
     * Writes the CSV (UTF-8 with BOM, ';' separator so it opens directly in pt-BR Excel/Numbers).
     *
     * @param  Builder<Lead>  $query
     * @param  resource  $handle
     */
    public function write(Builder $query, $handle): int
    {
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::COLUMNS, ';');

        $count = 0;

        $query->with(['icp:id,name', 'sources:id,lead_id,driver'])
            ->orderBy('id')
            ->chunk(500, function ($leads) use ($handle, &$count) {
                foreach ($leads as $lead) {
                    fputcsv($handle, $this->row($lead), ';');
                    $count++;
                }
            });

        return $count;
    }

    /** @return list<string|int|null> */
    private function row(Lead $lead): array
    {
        return [
            $lead->id,
            $lead->company_name,
            $lead->name,
            $lead->websiteUrl(),
            $lead->email,
            $lead->phone ? '+'.$lead->phone : null,
            match ($lead->whatsapp_valid) {
                true => __('yes'),
                false => __('no'),
                null => '',
            },
            Countries::name($lead->country),
            $lead->city,
            $lead->cnpj,
            $lead->score,
            $lead->status->label(),
            $lead->icp->name,
            $lead->sources->pluck('driver')->unique()->implode(', '),
            $lead->created_at->format('d/m/Y H:i'),
        ];
    }
}
