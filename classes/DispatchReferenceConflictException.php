<?php
declare(strict_types=1);

/**
 * DispatchReferenceConflictException — thrown when a duplicate dispatch_reference is used.
 */
class DispatchReferenceConflictException extends ApiException
{
    public function __construct(string $reference)
    {
        parent::__construct(
            "Dispatch reference conflict: {$reference} already used for this order",
            409
        );
    }
}
