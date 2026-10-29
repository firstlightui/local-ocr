<?php

namespace FirstlightUI\LocalOcr;

use FirstlightUI\LocalOcr\Events\TextRecognitionFailed;
use FirstlightUI\LocalOcr\Events\TextRecognized;

/**
 * @method $this textRecognized(\Closure|array|string $callback)
 * @method $this textRecognitionFailed(\Closure|array|string $callback)
 */
class PendingRecognition extends PendingOperation
{
    protected ?string $eventClass = TextRecognized::class;

    /**
     * @param  array<int, string>  $paths  page image paths, in page order
     */
    public function __construct(
        protected array $paths,
        protected array $options = [],
    ) {}

    /**
     * Preferred recognition languages as BCP 47 codes, in priority order.
     * iOS only; Android's bundled recognizer reads all Latin-script text.
     *
     * @param  array<int, string>  $languages
     */
    public function languages(array $languages): static
    {
        $this->options['languages'] = array_values($languages);

        return $this;
    }

    /**
     * Trade accuracy for speed. iOS only; Android has a single mode.
     */
    public function fast(): static
    {
        $this->options['recognitionLevel'] = 'fast';

        return $this;
    }

    protected function method(): string
    {
        return 'LocalOcr.Recognize';
    }

    protected function payload(): array
    {
        return array_merge($this->options, ['paths' => $this->paths]);
    }

    /**
     * @return array<int, class-string>
     */
    protected function failureEvents(): array
    {
        return [TextRecognitionFailed::class];
    }
}
