<?php
/**
 * ValidateRequest — lightweight JSON-Schema-style validation for API requests.
 *
 * Schemas live in api/contracts/<module>.json. Each action maps to a set of
 * required/optional fields with type constraints. The middleware is opt-in:
 * set API_CONTRACT_VALIDATION=1 in config/database.php to enable, or pass
 * ?_validate=1 on any request to enable per-request.
 *
 * Usage (gateway dispatch — step between auth and handler call):
 *
 *   ValidateRequest::validate($module, $action, body());
 */

class ValidateRequest
{
    /** @var array<string, array<string, mixed>> Parsed schemas keyed by module. */
    private static array $schemaCache = [];

    /** @var string Absolute path to the contracts directory. */
    private static string $contractDir = __DIR__ . '/../contracts';

    /**
     * Validate request data against the schema for $module::$action.
     *
     * @param string $module  API module name (e.g. 'inbound')
     * @param string $action  API action name (e.g. 'create')
     * @param array  $data    Request body (decoded JSON or $_POST)
     *
     * @throws ContractValidationException If validation fails.
     */
    public static function validate(string $module, string $action, array $data): void
    {
        $schema = self::loadSchema($module);
        if ($schema === null) {
            // No schema defined for this module — pass through silently.
            return;
        }

        $actionSchema = $schema['actions'][$action] ?? null;
        if ($actionSchema === null) {
            // No schema for this specific action — pass through.
            return;
        }

        $errors = [];

        // ── Check required fields ──────────────────────────────────────
        $required = $actionSchema['required'] ?? [];
        foreach ($required as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                $errors[$field] = "Field '{$field}' is required.";
            }
        }

        // ── Check typed fields ─────────────────────────────────────────
        $properties = $actionSchema['properties'] ?? [];
        foreach ($properties as $field => $rules) {
            if (!array_key_exists($field, $data)) continue;

            $value = $data[$field];

            // Type check
            $expectedType = $rules['type'] ?? null;
            if ($expectedType !== null) {
                $typeOk = self::checkType($value, $expectedType);
                if (!$typeOk) {
                    $errors[$field] = "Field '{$field}' must be of type '{$expectedType}'.";
                    continue;
                }
            }

            // Enum check
            $enum = $rules['enum'] ?? null;
            if ($enum !== null && $value !== '' && $value !== null) {
                if (!in_array($value, $enum, true)) {
                    $errors[$field] = "Field '{$field}' must be one of: " . implode(', ', $enum) . ".";
                }
            }

            // Minimum value (numeric)
            if (isset($rules['minimum']) && is_numeric($value)) {
                if ((float)$value < (float)$rules['minimum']) {
                    $errors[$field] = "Field '{$field}' must be at least {$rules['minimum']}.";
                }
            }

            // Maximum length (string)
            if (isset($rules['maxLength']) && is_string($value)) {
                if (mb_strlen($value) > (int)$rules['maxLength']) {
                    $errors[$field] = "Field '{$field}' must not exceed {$rules['maxLength']} characters.";
                }
            }

            // Pattern (regex)
            if (isset($rules['pattern']) && is_string($value)) {
                if (!preg_match($rules['pattern'], $value)) {
                    $errors[$field] = "Field '{$field}' does not match the required format.";
                }
            }
        }

        // ── Validate nested array items ────────────────────────────────
        $itemsSchema = $actionSchema['items'] ?? null;
        $itemsField  = $actionSchema['items_field'] ?? 'items';
        if ($itemsSchema !== null && isset($data[$itemsField]) && is_array($data[$itemsField])) {
            $itemRequired = $itemsSchema['required'] ?? [];
            $itemProps    = $itemsSchema['properties'] ?? [];
            foreach ($data[$itemsField] as $idx => $item) {
                foreach ($itemRequired as $field) {
                    if (!array_key_exists($field, $item) || $item[$field] === '' || $item[$field] === null) {
                        $errors["{$itemsField}[{$idx}].{$field}"] = "Item #{$idx}: field '{$field}' is required.";
                    }
                }
                foreach ($itemProps as $field => $rules) {
                    if (!array_key_exists($field, $item)) continue;
                    $expectedType = $rules['type'] ?? null;
                    if ($expectedType !== null && !self::checkType($item[$field], $expectedType)) {
                        $errors["{$itemsField}[{$idx}].{$field}"] = "Item #{$idx}: field '{$field}' must be of type '{$expectedType}'.";
                    }
                    if (isset($rules['minimum']) && is_numeric($item[$field])) {
                        if ((float)$item[$field] < (float)$rules['minimum']) {
                            $errors["{$itemsField}[{$idx}].{$field}"] = "Item #{$idx}: field '{$field}' must be at least {$rules['minimum']}.";
                        }
                    }
                }
            }
        }

        if (!empty($errors)) {
            throw new ContractValidationException('Validation failed.', $errors, 422);
        }
    }

    // ── Private helpers ────────────────────────────────────────────────

    /**
     * Load and parse schema from contracts directory (cached).
     */
    private static function loadSchema(string $module): ?array
    {
        if (isset(self::$schemaCache[$module])) {
            return self::$schemaCache[$module];
        }

        $file = self::$contractDir . '/' . $module . '.json';
        if (!is_file($file)) {
            self::$schemaCache[$module] = null;
            return null;
        }

        $raw = file_get_contents($file);
        $schema = json_decode($raw, true);
        self::$schemaCache[$module] = is_array($schema) ? $schema : null;
        return self::$schemaCache[$module];
    }

    /**
     * Check if a value matches the expected JSON-Schema type.
     */
    private static function checkType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string'  => is_string($value),
            'integer' => is_int($value) || (is_string($value) && ctype_digit($value)),
            'number'  => is_numeric($value),
            'boolean' => is_bool($value) || in_array($value, ['0', '1', 'true', 'false', 0, 1], true),
            'array'   => is_array($value),
            default   => true,
        };
    }

    /**
     * Check whether contract validation is enabled.
     *
     * Enabled when:
     *   - The constant API_CONTRACT_VALIDATION is truthy, OR
     *   - The request contains ?_validate=1
     */
    public static function isEnabled(): bool
    {
        if (defined('API_CONTRACT_VALIDATION') && API_CONTRACT_VALIDATION) {
            return true;
        }
        if (isset($_GET['_validate']) && $_GET['_validate'] === '1') {
            return true;
        }
        return false;
    }
}
