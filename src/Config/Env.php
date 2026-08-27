<?php

declare(strict_types=1);

namespace Kone\Config;

/**
 * Minimal .env loader (no external dependency).
 *
 * Parses KEY=VALUE lines from the project-root `.env` file, supports
 * # comments, empty lines and single/double-quoted values. The `.env`
 * file always wins over any pre-existing process environment variables
 * so that the project-root config is the single source of truth.
 */
final class Env
{
    public static function load(?string $dir = null): void
    {
        $dir ??= dirname(__DIR__, 2);
        $file = $dir . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (strlen($value) >= 2
                && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
                $value = substr($value, 1, -1);
            }

            if ($key === '') {
                continue;
            }

            // Respect already-set process env vars (e.g., from test bootstrap via proc_open)
            // Only set from .env if not already in process environment
            if (getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        return array_key_exists($key, $_ENV) ? $_ENV[$key] : $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return ($value === null || $value === '') ? $default : (int) $value;
    }
}