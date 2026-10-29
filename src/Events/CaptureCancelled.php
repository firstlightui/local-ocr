<?php

namespace FirstlightUI\LocalOcr\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * The user closed the scanner without saving any pages.
 */
class CaptureCancelled implements DispatchableFromNative
{
    use Dispatchable;

    public function __construct(
        public string $id,
    ) {}
}
