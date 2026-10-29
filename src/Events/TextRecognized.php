<?php

namespace FirstlightUI\LocalOcr\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * Text recognition finished for every page. $result follows the recognition result
 * contract documented in the README (contractVersion 1).
 */
class TextRecognized implements DispatchableFromNative
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public string $id,
        public array $result,
    ) {}
}
