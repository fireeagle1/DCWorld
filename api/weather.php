<?php
/**
 * Weather API Endpoint
 * Proxies Open-Meteo for Chorlton, Manchester weather data.
 * Returns current conditions + 7-day forecast.
 *
 * GET /weather.php
 *   Returns: { current: {...}, daily: [...] }
 */

require_once __DIR__ . '/auth_middleware.php';

// Require authentication
require_auth();

// Chorlton, Manchester coordinates
$lat = 53.4408;
$lon = -2.2726;

// Open-Meteo API — free, no key required
$apiURL = "https://api.open-meteo.com/v1/forecast?"
    . "latitude={$lat}&longitude={$lon}"
    . "&current=temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m,is_day"
    . "&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset"
    . "&timezone=Europe%2FLondon"
    . "&forecast_days=7"
    . "&temperature_unit=celsius"
    . "&wind_speed_unit=mph";

$ch = curl_init($apiURL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    json_error('Weather data unavailable', 502);
}

$data = json_decode($response, true);

if (!$data || !isset($data['current']) || !isset($data['daily'])) {
    json_error('Invalid weather response', 502);
}

// Map WMO weather codes to human-readable descriptions and SF Symbols
function weather_description(int $code, bool $isDay = true): array {
    $map = [
        0  => ['Clear sky', $isDay ? 'sun.max.fill' : 'moon.stars.fill'],
        1  => ['Mainly clear', $isDay ? 'sun.max.fill' : 'moon.fill'],
        2  => ['Partly cloudy', $isDay ? 'cloud.sun.fill' : 'cloud.moon.fill'],
        3  => ['Overcast', 'cloud.fill'],
        45 => ['Fog', 'cloud.fog.fill'],
        48 => ['Freezing fog', 'cloud.fog.fill'],
        51 => ['Light drizzle', 'cloud.drizzle.fill'],
        53 => ['Drizzle', 'cloud.drizzle.fill'],
        55 => ['Heavy drizzle', 'cloud.drizzle.fill'],
        56 => ['Freezing drizzle', 'cloud.sleet.fill'],
        57 => ['Heavy freezing drizzle', 'cloud.sleet.fill'],
        61 => ['Light rain', 'cloud.rain.fill'],
        63 => ['Rain', 'cloud.rain.fill'],
        65 => ['Heavy rain', 'cloud.heavyrain.fill'],
        66 => ['Freezing rain', 'cloud.sleet.fill'],
        67 => ['Heavy freezing rain', 'cloud.sleet.fill'],
        71 => ['Light snow', 'cloud.snow.fill'],
        73 => ['Snow', 'cloud.snow.fill'],
        75 => ['Heavy snow', 'cloud.snow.fill'],
        77 => ['Snow grains', 'cloud.snow.fill'],
        80 => ['Light showers', 'cloud.rain.fill'],
        81 => ['Showers', 'cloud.rain.fill'],
        82 => ['Heavy showers', 'cloud.heavyrain.fill'],
        85 => ['Snow showers', 'cloud.snow.fill'],
        86 => ['Heavy snow showers', 'cloud.snow.fill'],
        95 => ['Thunderstorm', 'cloud.bolt.rain.fill'],
        96 => ['Thunderstorm with hail', 'cloud.bolt.rain.fill'],
        99 => ['Thunderstorm with heavy hail', 'cloud.bolt.rain.fill'],
    ];

    return $map[$code] ?? ['Unknown', 'questionmark.circle'];
}

$current = $data['current'];
$daily = $data['daily'];
$isDay = (bool)($current['is_day'] ?? true);

[$currentDesc, $currentIcon] = weather_description((int)$current['weather_code'], $isDay);

$result = [
    'location' => 'Chorlton',
    'current' => [
        'temperature' => round($current['temperature_2m']),
        'feelsLike' => round($current['apparent_temperature']),
        'humidity' => $current['relative_humidity_2m'],
        'windSpeed' => round($current['wind_speed_10m']),
        'description' => $currentDesc,
        'icon' => $currentIcon,
        'isDay' => $isDay,
    ],
    'daily' => [],
];

// Build daily forecast (skip today = index 0, include next 3 days)
$dayCount = count($daily['time'] ?? []);
for ($i = 0; $i < $dayCount; $i++) {
    [$dayDesc, $dayIcon] = weather_description((int)$daily['weather_code'][$i], true);
    $result['daily'][] = [
        'date' => $daily['time'][$i],
        'high' => round($daily['temperature_2m_max'][$i]),
        'low' => round($daily['temperature_2m_min'][$i]),
        'precipChance' => $daily['precipitation_probability_max'][$i] ?? 0,
        'description' => $dayDesc,
        'icon' => $dayIcon,
        'sunrise' => $daily['sunrise'][$i] ?? null,
        'sunset' => $daily['sunset'][$i] ?? null,
    ];
}

json_response($result);
