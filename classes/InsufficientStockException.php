<?php
declare(strict_types=1);

/**
 * InsufficientStockException — thrown when FEFO allocation cannot satisfy requested quantity.
 */
class InsufficientStockException extends ApiException
{
    public function __construct(string $sku, float $requested, float $available)
    {
        parent::__construct(
            "Insufficient stock for {$sku}: requested {$requested}, available {$available}",
            409
        );
    }
}
