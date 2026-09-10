<?php

namespace App\Domains\Icp;

use App\Domains\Icp\Data\IcpCriteria;
use App\Domains\Icp\Exceptions\IcpParseException;
use App\Support\Countries;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Turns the answer pasted from an AI agent into IcpCriteria.
 * Tolerates markdown fences, surrounding text, trailing commas and single quotes.
 */
class IcpJsonParser
{
    public function parse(string $input): IcpCriteria
    {
        $json = $this->extractJson($input);
        $data = $this->decode($json);

        $data = $this->normalize($data);

        $this->validateSchema($data);

        return IcpCriteria::fromArray($data);
    }

    private function extractJson(string $input): string
    {
        $input = trim($input);

        if ($input === '') {
            throw new IcpParseException(__('Paste the agent answer before continuing.'));
        }

        // 1. Remove fences ```json ... ```
        $input = preg_replace('/^\s*```(?:json)?\s*$/mi', '', $input) ?? $input;

        // 2. Extract the first balanced {...} block, ignoring text before/after.
        $start = strpos($input, '{');
        $end = strrpos($input, '}');

        if ($start === false || $end === false || $end < $start) {
            throw new IcpParseException(__('No JSON found in the answer. It must contain an object between braces { }.'));
        }

        return substr($input, $start, $end - $start + 1);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            $data = json_decode($this->repair($json), true);
        }

        if (! is_array($data)) {
            throw new IcpParseException(__('The JSON is malformed: :error. Ask the agent to answer with the JSON only.', ['error' => json_last_error_msg()]));
        }

        return $data;
    }

    /** Common fixes for LLM output: trailing commas, single quotes, comments. */
    private function repair(string $json): string
    {
        // Comments: // and /* */
        $json = preg_replace('~//[^\n"]*$~m', '', $json) ?? $json;
        $json = preg_replace('~/\*.*?\*/~s', '', $json) ?? $json;

        // Single quotes around keys and values (when there are no double quotes inside)
        $json = preg_replace_callback("/'([^'\\\\]*(?:\\\\.[^'\\\\]*)*)'/", fn ($m) => '"'.str_replace('"', '\"', $m[1]).'"', $json) ?? $json;

        // Trailing comma before } or ]
        $json = preg_replace('/,\s*([}\]])/', '$1', $json) ?? $json;

        // Unquoted keys: { industry: "x" }
        $json = preg_replace('/([{,]\s*)([A-Za-z_][A-Za-z0-9_]*)\s*:/', '$1"$2":', $json) ?? $json;

        return $json;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        foreach (['keywords', 'countries', 'regions', 'job_titles'] as $list) {
            if (isset($data[$list]) && is_string($data[$list])) {
                $data[$list] = array_values(array_filter(array_map('trim', preg_split('/[,\n]/', $data[$list]) ?: [])));
            }
            if (isset($data[$list]) && is_array($data[$list])) {
                $data[$list] = array_values(array_filter(
                    array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $data[$list]),
                    fn ($v) => $v !== '',
                ));
            }
        }

        if (isset($data['countries']) && is_array($data['countries'])) {
            $data['countries'] = array_values(array_unique(array_map(
                fn (string $c) => strtoupper($this->countryCode($c)),
                $data['countries'],
            )));
        }

        if (array_key_exists('company_size', $data)) {
            $data['company_size'] = $this->companySize($data['company_size']);
        }

        if (isset($data['signals']) && is_array($data['signals'])) {
            $s = $data['signals'];
            if (isset($s['has_website']) && ! is_bool($s['has_website'])) {
                $s['has_website'] = filter_var($s['has_website'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
            if (isset($s['min_reviews']) && is_numeric($s['min_reviews'])) {
                $s['min_reviews'] = (int) $s['min_reviews'];
            }
            if (isset($s['min_rating']) && is_numeric($s['min_rating'])) {
                $s['min_rating'] = (float) $s['min_rating'];
            }
            $data['signals'] = $s;
        }

        return $data;
    }

    private function countryCode(string $value): string
    {
        return Countries::code($value) ?? $value;
    }

    private function companySize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $v = mb_strtolower(trim((string) $value));
        $v = str_replace(['–', '—', ' a ', ' to ', ' '], ['-', '-', '-', '-', ''], $v);

        return match (true) {
            in_array($v, ['1-10', '0-10', 'micro', 'solo'], true) => '1-10',
            in_array($v, ['11-50', '10-50', 'small', 'pequena'], true) => '11-50',
            in_array($v, ['51-200', '50-200', 'medium', 'média', 'media'], true) => '51-200',
            in_array($v, ['200+', '201+', '200-500', '500+', '1000+', 'large', 'grande', 'enterprise'], true) => '200+',
            default => null,
        };
    }

    /** @param  array<string, mixed>  $data */
    private function validateSchema(array $data): void
    {
        $schema = file_get_contents(resource_path('schemas/icp.schema.json'));
        $validator = new Validator;
        $result = $validator->validate(json_decode((string) json_encode($data)), $schema ?: '{}');

        $error = $result->error();

        if ($error === null) {
            return;
        }

        $formatted = (new ErrorFormatter)->formatFlat($error);
        $keyword = $error->keyword();

        $friendly = match (true) {
            $keyword === 'required' => __('Missing required fields: :fields.', ['fields' => implode(', ', (array) ($error->args()['missing'] ?? []))]),
            default => __('The JSON does not match the expected format: :details', ['details' => implode(' ', $formatted)]),
        };

        throw new IcpParseException($friendly);
    }
}
