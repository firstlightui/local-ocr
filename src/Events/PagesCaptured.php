<?php

namespace FirstlightUI\LocalOcr\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * The user finished scanning. Each page is ['path' => string, 'width' => int,
 * 'height' => int]; the JPEGs live in the app's private temporary storage.
 */
class PagesCaptured implements DispatchableFromNative
{
    use Dispatchable;

    /**
     * @param  array<int, array{path: string, width: int, height: int}>  $pages
     */
    public function __construct(
        public string $id,
        public array $pages,
    ) {}
}
