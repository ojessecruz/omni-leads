<?php

namespace App\Domains\Icp\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A circle on the map: search only within radiusKm of (lat, lng).
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class GeoArea implements Arrayable
{
    public const float MIN_RADIUS_KM = 0.5;

    public const float MAX_RADIUS_KM = 50.0;

    private const float EARTH_RADIUS_KM = 6371.0;

    public function __construct(
        public float $lat,
        public float $lng,
        public float $radiusKm,
        public string $label = '',
    ) {}

    /**
     * Invalid or out-of-range data returns null (forward-compatible, like the rest of the criteria).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        if (! is_numeric($data['lat'] ?? null) || ! is_numeric($data['lng'] ?? null) || ! is_numeric($data['radius_km'] ?? null)) {
            return null;
        }

        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];

        if (abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        $radius = min(self::MAX_RADIUS_KM, max(self::MIN_RADIUS_KM, (float) $data['radius_km']));
        $label = is_scalar($data['label'] ?? null) ? trim((string) $data['label']) : '';

        return new self(round($lat, 6), round($lng, 6), round($radius, 2), mb_substr($label, 0, 120));
    }

    /** Great-circle distance (haversine) from the center. */
    public function distanceKm(float $lat, float $lng): float
    {
        $dLat = deg2rad($lat - $this->lat);
        $dLng = deg2rad($lng - $this->lng);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($this->lat)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function contains(float $lat, float $lng): bool
    {
        return $this->distanceKm($lat, $lng) <= $this->radiusKm;
    }

    /**
     * Rectangle that encloses the circle (Places Text Search only restricts by rectangle).
     *
     * @return array{low: array{latitude: float, longitude: float}, high: array{latitude: float, longitude: float}}
     */
    public function boundingBox(): array
    {
        $dLat = rad2deg($this->radiusKm / self::EARTH_RADIUS_KM);
        $dLng = rad2deg($this->radiusKm / (self::EARTH_RADIUS_KM * max(cos(deg2rad($this->lat)), 0.01)));

        return [
            'low' => ['latitude' => max(-90.0, $this->lat - $dLat), 'longitude' => max(-180.0, $this->lng - $dLng)],
            'high' => ['latitude' => min(90.0, $this->lat + $dLat), 'longitude' => min(180.0, $this->lng + $dLng)],
        ];
    }

    /** "Moema (5 km)" or the coordinates when there is no label. */
    public function displayName(): string
    {
        $where = $this->label !== '' ? $this->label : sprintf('%.4f, %.4f', $this->lat, $this->lng);

        return sprintf('%s (%s km)', $where, rtrim(rtrim(number_format($this->radiusKm, 1, '.', ''), '0'), '.'));
    }

    /** @return array{lat: float, lng: float, radius_km: float, label: string} */
    public function toArray(): array
    {
        return [
            'lat' => $this->lat,
            'lng' => $this->lng,
            'radius_km' => $this->radiusKm,
            'label' => $this->label,
        ];
    }
}
