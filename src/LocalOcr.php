<?php

namespace FirstlightUI\LocalOcr;

class LocalOcr
{
    /**
     * Report whether document capture and OCR are available on the device.
     * Returns null when the native bridge is unavailable or fails.
     *
     * @return array<string, mixed>|null
     */
    public function capabilities(): ?array
    {
        $decoded = $this->call('LocalOcr.GetCapabilities', []);

        return ($decoded['status'] ?? null) === 'error' ? null : $decoded;
    }

    /**
     * Open the system document scanner. The user can scan any number of
     * pages; they arrive as a PagesCaptured event with paths to JPEGs in the
     * app's private temporary storage. Delete them with discard() when done.
     */
    public function capture(): PendingCapture
    {
        return new PendingCapture;
    }

    /**
     * Recognize text in one or more page images, in order. Recognition runs
     * off the main thread; its result arrives as a TextRecognized or
     * TextRecognitionFailed event carrying this request's id.
     *
     * @param  string|array<int, string|array{path: string}>  $pages  paths, or pages from PagesCaptured
     */
    public function recognize(string|array $pages, array $options = []): PendingRecognition
    {
        return new PendingRecognition($this->paths($pages), $options);
    }

    /**
     * Delete captured pages. Only files this plugin captured are deleted.
     * Returns the number of files removed.
     *
     * @param  string|array<int, string|array{path: string}>  $pages  paths, or pages from PagesCaptured
     */
    public function discard(string|array $pages): int
    {
        return (int) ($this->call('LocalOcr.Discard', ['paths' => $this->paths($pages)])['deleted'] ?? 0);
    }

    /**
     * @return array<int, string>
     */
    protected function paths(string|array $pages): array
    {
        return array_values(array_map(
            fn (string|array $page) => is_array($page) ? (string) $page['path'] : $page,
            (array) $pages,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function call(string $method, array $parameters): ?array
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        $decoded = json_decode((string) nativephp_call($method, json_encode((object) $parameters)), true);

        return is_array($decoded) ? $decoded : null;
    }
}
