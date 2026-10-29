<?php

namespace FirstlightUI\LocalOcr\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * Text recognition could not run or failed. $code is one of
 * INVALID_PARAMETERS, FILE_NOT_FOUND, DECODE_FAILED or RECOGNITION_FAILED.
 */
class TextRecognitionFailed implements DispatchableFromNative
{
    use Dispatchable;

    public function __construct(
        public string $id,
        public string $code,
        public string $message,
    ) {}
}
