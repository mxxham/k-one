<?php
declare(strict_types=1);

/**
 * Custom Exception Classes for K-one WMS
 * Provides typed exceptions for proper error handling and recovery.
 */

// Base exception for all WMS errors
class WmsException extends Exception {
    protected string $module;
    protected array $context;
    
    public function __construct(string $message, string $module = 'general', array $context = [], int $code = 0, ?Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->module = $module;
        $this->context = $context;
    }
    
    public function getModule(): string { return $this->module; }
    public function getContext(): array { return $this->context; }
    public function toArray(): array {
        return [
            'error' => true,
            'message' => $this->getMessage(),
            'module' => $this->module,
            'code' => $this->getCode(),
            'context' => $this->context,
        ];
    }
}

// Stock-related exceptions
class StockException extends WmsException {
    public function __construct(string $message, array $context = [], ?Throwable $previous = null) {
        parent::__construct($message, 'stock', $context, 0, $previous);
    }
}

class InsufficientStockException extends StockException {
    public function __construct(int $productId, float $requested, float $available, string $location = '') {
        $msg = "Insufficient stock: requested {$requested}, available {$available}";
        if ($location) $msg .= " at {$location}";
        parent::__construct($msg, [
            'product_id' => $productId,
            'requested' => $requested,
            'available' => $available,
            'location' => $location,
        ]);
    }
}

class StockLockedException extends StockException {
    public function __construct(string $lpnCode, string $lockedBy) {
        parent::__construct("Stock LPN {$lpnCode} is locked by {$lockedBy}", [
            'lpn_code' => $lpnCode,
            'locked_by' => $lockedBy,
        ]);
    }
}

// Allocation exceptions
class AllocationException extends WmsException {
    public function __construct(string $message, array $context = [], ?Throwable $previous = null) {
        parent::__construct($message, 'allocation', $context, 0, $previous);
    }
}

class FEFOAllocationException extends AllocationException {
    public function __construct(int $productId, string $reason = '') {
        parent::__construct("FEFO allocation failed for product #{$productId}: {$reason}", [
            'product_id' => $productId,
            'reason' => $reason,
        ]);
    }
}

// Location exceptions
class LocationException extends WmsException {
    public function __construct(string $message, array $context = [], ?Throwable $previous = null) {
        parent::__construct($message, 'location', $context, 0, $previous);
    }
}

class LocationBlockedException extends LocationException {
    public function __construct(string $locationCode, string $reason = '') {
        parent::__construct("Location {$locationCode} is blocked: {$reason}", [
            'location_code' => $locationCode,
            'reason' => $reason,
        ]);
    }
}

// Order exceptions
class OrderException extends WmsException {
    public function __construct(string $message, array $context = [], ?Throwable $previous = null) {
        parent::__construct($message, 'order', $context, 0, $previous);
    }
}

class OrderNotFoundException extends OrderException {
    public function __construct(string $orderType, $orderId) {
        parent::__construct("{$orderType} order #{$orderId} not found", [
            'order_type' => $orderType,
            'order_id' => $orderId,
        ], null);
    }
}

class InvalidOrderStateException extends OrderException {
    public function __construct(string $orderType, $orderId, string $currentState, string $requiredState) {
        parent::__construct("{$orderType} #{$orderId} is {$currentState}, cannot perform action (requires {$requiredState})", [
            'order_type' => $orderType,
            'order_id' => $orderId,
            'current_state' => $currentState,
            'required_state' => $requiredState,
        ]);
    }
}

// Picking exceptions
class PickingException extends WmsException {
    public function __construct(string $message, array $context = [], ?Throwable $previous = null) {
        parent::__construct($message, 'picking', $context, 0, $previous);
    }
}

class PickReferenceConflictException extends PickingException {
    public function __construct(string $lpnCode, string $detail = '') {
        parent::__construct("Pick reference conflict for LPN {$lpnCode}: {$detail}", [
            'lpn_code' => $lpnCode,
            'detail' => $detail,
        ]);
    }
}

// Dispatch exceptions
class DispatchException extends WmsException {
    public function __construct(string $message, array $context = [], ?Throwable $previous = null) {
        parent::__construct($message, 'dispatch', $context, 0, $previous);
    }
}

class DispatchReferenceConflictException extends DispatchException {
    public function __construct(string $reference, string $detail = '') {
        parent::__construct("Dispatch reference conflict: {$reference}: {$detail}", [
            'reference' => $reference,
            'detail' => $detail,
        ]);
    }
}

// LPN exceptions
class InvalidLpnException extends WmsException {
    public function __construct(string $lpnCode, string $detail = '') {
        parent::__construct("Invalid LPN {$lpnCode}: {$detail}", [
            'lpn_code' => $lpnCode,
            'detail' => $detail,
        ]);
    }
}

// Import/Export exceptions
class ImportException extends WmsException {
    public function __construct(string $message, array $context = [], ?Throwable $previous = null) {
        parent::__construct($message, 'import', $context, 0, $previous);
    }
}

class ValidationException extends WmsException {
    public function __construct(array $errors) {
        $messages = array_map(fn($e) => is_array($e) ? implode(', ', $e) : $e, $errors);
        parent::__construct("Validation failed: " . implode('; ', $messages), 'validation', ['errors' => $errors]);
    }
}

// API exceptions
class ApiException extends WmsException {
    public function __construct(string $message, int $httpCode = 400, array $context = [], ?Throwable $previous = null) {
        parent::__construct($message, 'api', $context, $httpCode, $previous);
    }
}

// Auth exceptions
class AuthException extends WmsException {
    public function __construct(string $message = 'Unauthorized', array $context = [], ?Throwable $previous = null) {
        parent::__construct($message, 'auth', $context, 401, $previous);
    }
}
