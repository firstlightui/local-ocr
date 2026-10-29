<?php

namespace FirstlightUI\LocalOcr\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array<string, mixed>|null capabilities()
 * @method static \FirstlightUI\LocalOcr\PendingCapture capture()
 * @method static \FirstlightUI\LocalOcr\PendingRecognition recognize(string|array $pages, array $options = [])
 * @method static int discard(string|array $pages)
 *
 * @see \FirstlightUI\LocalOcr\LocalOcr
 */
class LocalOcr extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \FirstlightUI\LocalOcr\LocalOcr::class;
    }
}
