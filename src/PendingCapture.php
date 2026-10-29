<?php

namespace FirstlightUI\LocalOcr;

use FirstlightUI\LocalOcr\Events\CaptureCancelled;
use FirstlightUI\LocalOcr\Events\CaptureFailed;
use FirstlightUI\LocalOcr\Events\PagesCaptured;

/**
 * @method $this pagesCaptured(\Closure|array|string $callback)
 * @method $this captureCancelled(\Closure|array|string $callback)
 * @method $this captureFailed(\Closure|array|string $callback)
 */
class PendingCapture extends PendingOperation
{
    protected ?string $eventClass = PagesCaptured::class;

    protected function method(): string
    {
        return 'LocalOcr.Capture';
    }

    protected function payload(): array
    {
        return [];
    }

    /**
     * @return array<int, class-string>
     */
    protected function failureEvents(): array
    {
        return [CaptureCancelled::class, CaptureFailed::class];
    }
}
