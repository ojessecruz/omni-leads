<?php

namespace App\Support;

/**
 * Sample data shown on the landing page, matched to the visitor's language so the
 * example business looks local: Brazil for pt_BR, the US for en, Mexico for es.
 */
final class LandingSample
{
    /**
     * @return array{
     *     sources: string,
     *     icp: array{industry: string, keywords: list<string>, countries: list<string>, regions: list<string>},
     *     lead: array{name: string, domain: string, city: string, country: string, sources: string, email: string},
     *     log: list<array{0: string, 1: string, 2: string, 3: int|null}>
     * }
     */
    public static function for(string $locale): array
    {
        return match ($locale) {
            'pt_BR' => [
                'sources' => 'google_places · cnpj_br',
                'icp' => [
                    'industry' => 'estética',
                    'keywords' => ['clínica de estética', 'day spa'],
                    'countries' => ['BR', 'PT'],
                    'regions' => ['São Paulo', 'Lisboa'],
                ],
                'lead' => [
                    'name' => 'Clínica Bella Estética',
                    'domain' => 'bellaestetica.com.br',
                    'city' => 'São Paulo',
                    'country' => 'BR',
                    'sources' => 'google_places + cnpj_br',
                    'email' => 'contato@bellaestetica.com.br',
                ],
                'log' => [
                    ['google_places', 'Clínica Bella Estética', 'São Paulo, BR', 85],
                    ['google_places', 'Day Spa Moema', 'São Paulo, BR', 70],
                    ['cnpj_br', 'Harmoniza Face Ltda', 'Campinas, BR', 55],
                    ['google_places', 'Estética Lisboa Premium', 'Lisboa, PT', 70],
                    ['apollo', 'Ana Silva · Sócia @ Spa Ana', 'Rio de Janeiro, BR', 60],
                    ['cnpj_br', 'Corte & Cia ME', 'Guarulhos, BR', 35],
                    ['google_places', 'Studio Pele', 'Santos, BR', 45],
                    ['hunter', '↳ contato@bellaestetica.com.br', '+15 email', null],
                    ['evolution', '↳ +55 11 99999-0000 · WhatsApp ✓', '+15 whatsapp', null],
                    ['google_places', 'Espaço Renova', 'Curitiba, BR', 80],
                ],
            ],
            'es' => [
                'sources' => 'google_places · apollo',
                'icp' => [
                    'industry' => 'estética',
                    'keywords' => ['clínica estética', 'day spa'],
                    'countries' => ['MX', 'ES'],
                    'regions' => ['Ciudad de México', 'Madrid'],
                ],
                'lead' => [
                    'name' => 'Clínica Bella Estética',
                    'domain' => 'bellaestetica.mx',
                    'city' => 'Ciudad de México',
                    'country' => 'MX',
                    'sources' => 'google_places + apollo',
                    'email' => 'contacto@bellaestetica.mx',
                ],
                'log' => [
                    ['google_places', 'Clínica Bella Estética', 'Ciudad de México, MX', 85],
                    ['google_places', 'Day Spa Polanco', 'Ciudad de México, MX', 70],
                    ['google_places', 'Dermo Estética Chapultepec', 'Guadalajara, MX', 55],
                    ['google_places', 'Estética Madrid Premium', 'Madrid, ES', 70],
                    ['apollo', 'Lucía Gómez · Owner @ Spa Lucía', 'Monterrey, MX', 60],
                    ['google_places', 'Barbería El Corte', 'Puebla, MX', 35],
                    ['google_places', 'Studio Piel', 'Querétaro, MX', 45],
                    ['hunter', '↳ contacto@bellaestetica.mx', '+15 email', null],
                    ['evolution', '↳ +52 55 1234 5678 · WhatsApp ✓', '+15 whatsapp', null],
                    ['google_places', 'Espacio Renova', 'Mérida, MX', 80],
                ],
            ],
            default => [
                'sources' => 'google_places · apollo',
                'icp' => [
                    'industry' => 'aesthetics',
                    'keywords' => ['med spa', 'day spa'],
                    'countries' => ['US', 'CA'],
                    'regions' => ['Texas', 'Ontario'],
                ],
                'lead' => [
                    'name' => 'Bella Aesthetics Studio',
                    'domain' => 'bellaaesthetics.com',
                    'city' => 'Austin',
                    'country' => 'US',
                    'sources' => 'google_places + apollo',
                    'email' => 'hello@bellaaesthetics.com',
                ],
                'log' => [
                    ['google_places', 'Bella Aesthetics Studio', 'Austin, US', 85],
                    ['google_places', 'Glow Day Spa', 'Austin, US', 70],
                    ['google_places', 'Lakeside Med Spa', 'Dallas, US', 55],
                    ['google_places', 'Maple Skin Clinic', 'Toronto, CA', 70],
                    ['apollo', 'Emily Carter · Owner @ Carter Spa', 'Houston, US', 60],
                    ['google_places', 'Fade Factory Barbers', 'San Antonio, US', 35],
                    ['google_places', 'Studio Skin', 'El Paso, US', 45],
                    ['hunter', '↳ hello@bellaaesthetics.com', '+15 email', null],
                    ['evolution', '↳ +1 512 555 0142 · WhatsApp ✓', '+15 whatsapp', null],
                    ['google_places', 'Renew Wellness', 'Ottawa, CA', 80],
                ],
            ],
        };
    }
}
