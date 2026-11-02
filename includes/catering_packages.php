<?php
declare(strict_types=1);

/**
 * Shared, schema-free catering package settings. The legacy rate keys remain
 * authoritative so existing bookings and integrations keep their contract.
 */
function catering_package_definitions(): array
{
    return [
        'silver' => [
            'package_id' => 'package-1',
            'rate_key' => 'catering_silver',
            'name_key' => 'catering_silver_name',
            'menu_key' => 'catering_silver_menu_json',
            'default_name' => 'Package 1',
            'default_rate' => '550',
            'default_menu' => [
                'Beef Tenderloin with Mushroom Sauce',
                'Chicken Cordon Bleu',
                'Pork Shanghai Roll',
                'Canton Sotanghon',
                'Soup',
                'Rice',
                'Coffee Jelly',
                'Iced Tea',
            ],
        ],
        'gold' => [
            'package_id' => 'package-2',
            'rate_key' => 'catering_gold',
            'name_key' => 'catering_gold_name',
            'menu_key' => 'catering_gold_menu_json',
            'default_name' => 'Package 2',
            'default_rate' => '600',
            'default_menu' => [
                'Beef with Broccoli',
                "Sevilla's Chicken",
                'Pork Asado',
                'Fish Fillet w/ tartar dip',
                'Mixed Vegetables with Quail Egg',
                'Soup',
                'Rice',
                'Buko Pandan',
                'Cucumber Lemonade',
            ],
        ],
        'platinum' => [
            'package_id' => 'package-3',
            'rate_key' => 'catering_platinum',
            'name_key' => 'catering_platinum_name',
            'menu_key' => 'catering_platinum_menu_json',
            'default_name' => 'Package 3',
            'default_rate' => '650',
            'default_menu' => [
                'Beef Caldereta',
                'Pandan Chicken',
                'Pork Hamonado',
                'Sweet & Sour Fish',
                'Calamares',
                'Chopsuey',
                'Soup',
                'Rice',
                'Cathedral Window',
                'Lemon Jelly Juice',
            ],
        ],
    ];
}

function catering_package_text_length(string $value): int
{
    if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
    $matches = preg_match_all('/./us', $value);
    return $matches === false ? strlen($value) : $matches;
}

function catering_package_normalize_name($value): ?string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) return null;
    $name = trim($value);
    if ($name === '' || catering_package_text_length($name) > 60 || preg_match('/[\x00-\x1F\x7F]/u', $name)) return null;
    return $name;
}

function catering_package_normalize_rate($value): ?string
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) return null;
    $rate = trim((string)$value);
    if (!preg_match('/\A[0-9]{1,7}(?:\.[0-9]{1,4})?\z/D', $rate)) return null;
    $numericRate = (float)$rate;
    if (!is_finite($numericRate) || $numericRate < 0 || $numericRate > 1000000) return null;
    return $rate;
}

function catering_package_format_rate($value): string
{
    $rate = catering_package_normalize_rate($value);
    if ($rate === null) return '550.00';
    $fraction = str_contains($rate, '.') ? rtrim(substr($rate, strpos($rate, '.') + 1), '0') : '';
    $precision = max(2, min(4, strlen($fraction)));
    return number_format((float)$rate, $precision);
}

function catering_package_normalize_menu($value): ?array
{
    if (is_string($value)) {
        if (preg_match('//u', $value) !== 1) return null;
        $items = preg_split('/\R/u', $value);
        if (!is_array($items)) return null;
    } elseif (is_array($value)) {
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) return null;
        $items = $value;
    } else {
        return null;
    }

    $cleanItems = [];
    $totalCharacters = 0;
    foreach ($items as $item) {
        if (!is_string($item) || preg_match('//u', $item) !== 1) return null;
        $item = trim($item);
        if ($item === '') continue;
        $length = catering_package_text_length($item);
        if ($length > 120 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $item)) return null;
        $totalCharacters += $length;
        if ($totalCharacters > 3000 || count($cleanItems) >= 30) return null;
        $cleanItems[] = $item;
    }

    return $cleanItems === [] ? null : $cleanItems;
}

function catering_packages_from_settings(array $settings): array
{
    $packages = [];
    foreach (catering_package_definitions() as $key => $definition) {
        $rate = catering_package_normalize_rate($settings[$definition['rate_key']] ?? null)
            ?? $definition['default_rate'];
        $name = catering_package_normalize_name($settings[$definition['name_key']] ?? null)
            ?? $definition['default_name'];
        $menu = $definition['default_menu'];
        $storedMenu = $settings[$definition['menu_key']] ?? null;
        if (is_string($storedMenu)) {
            $decoded = json_decode($storedMenu, true);
            $normalizedMenu = catering_package_normalize_menu($decoded);
            if ($normalizedMenu !== null) $menu = $normalizedMenu;
        }

        $packages[$key] = [
            'key' => $key,
            'package_id' => $definition['package_id'],
            'name' => $name,
            'rate' => $rate,
            'menu' => $menu,
        ];
    }
    return $packages;
}

/** Validate one package and return only its compatible system_settings keys. */
function catering_package_settings_for_post(string $key, array $post): array
{
    $definitions = catering_package_definitions();
    if (!isset($definitions[$key])) throw new InvalidArgumentException('Choose a valid catering package.');
    $definition = $definitions[$key];
    $name = catering_package_normalize_name($post[$definition['name_key']] ?? null);
    if ($name === null) {
        throw new InvalidArgumentException($definition['default_name'] . ' needs a name of 1 to 60 characters.');
    }
    $rate = catering_package_normalize_rate($post[$definition['rate_key']] ?? null);
    if ($rate === null) {
        throw new InvalidArgumentException($definition['default_name'] . ' price must be from ₱0 to ₱1,000,000 per pax, with up to four decimal places.');
    }
    $menu = catering_package_normalize_menu($post['catering_' . $key . '_menu'] ?? null);
    if ($menu === null) {
        throw new InvalidArgumentException($definition['default_name'] . ' menu needs 1 to 30 items, each no longer than 120 characters and 3,000 characters total.');
    }
    return [
        $definition['rate_key'] => $rate,
        $definition['name_key'] => $name,
        $definition['menu_key'] => json_encode($menu, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
    ];
}

/** Validate all package fields from the general Preferences form. */
function catering_package_settings_from_post(array $post): array
{
    $settings = [];
    foreach (array_keys(catering_package_definitions()) as $key) {
        $settings = array_merge($settings, catering_package_settings_for_post($key, $post));
    }
    return $settings;
}
