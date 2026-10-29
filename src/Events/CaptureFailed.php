<?php

namespace FirstlightUI\LocalOcr\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * The scanner could not run or its pages could not be saved. $code is one of
 * INVALID_PARAMETERS, CAPTURE_UNAVAILABLE, CAPTURE_FAILED or SAVE_FAILED.
 */
class CaptureFailed implements DispatchableFromNative
{
    use Dispatchable;

    public function __construct(
        public string $id,
        public string $code,
        public string $message,
    ) {}
}
