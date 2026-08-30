<?php
/**
 * ContractValidationException — custom exception for API contract validation errors.
 *
 * Thrown by ValidateRequest when request data fails schema validation.
 * Caught by the gateway (api/index.php) and converted to a JSON 400 response.
 *
 * Named ContractValidationException to avoid collision with the existing
 * ApiException in classes/exceptions.php (which extends WmsException).
 */

class ContractValidationException extends \RuntimeException
{
    /** @var array<string, string> Field-level errors (field => message) */
    private array $errors;

    public function __construct(string $message, array $errors = [], int $code = 400)
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }

    /** @return array<string, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** Shape matching the existing json_err() contract. */
    public function toJson(): array
    {
        return [
            'success' => false,
            'message' => $this->getMessage(),
            'errors'  => $this->errors,
        ];
    }
}
