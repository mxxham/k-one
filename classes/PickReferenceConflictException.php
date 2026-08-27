<?php
declare(strict_types=1);

/**
 * PickReferenceConflictException — thrown when a duplicate pick_reference is used.
 */
class PickReferenceConflictException extends ApiException
{
    public function __construct(string $reference)
    {
        parent::__construct(
            "Pick reference conflict: {$reference} already used for this picklist item",
            409
        );
    }
}
