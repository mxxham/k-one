<?php
declare(strict_types=1);

/**
 * InvalidLpnException — thrown when an LPN code is not found, is blocked, or is not staged.
 */
class InvalidLpnException extends ApiException
{
    public function __construct(string $lpn, string $reason = 'not found or blocked')
    {
        parent::__construct(
            "Invalid LPN {$lpn}: {$reason}",
            404
        );
    }
}
