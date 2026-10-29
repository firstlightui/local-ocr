<?php

namespace FirstlightUI\LocalOcr;

use Illuminate\Support\Str;
use Native\Mobile\Concerns\HandlesNativeCallbacks;

/**
 * A native call whose outcome arrives later as an event carrying its id.
 * Starts automatically when the builder goes out of scope, so a chain such as
 * LocalOcr::capture()->pagesCaptured(...) needs no start().
 */
abstract class PendingOperation
{
    use HandlesNativeCallbacks;

    protected ?string $id = null;

    protected ?string $eventClass = null;

    protected bool $started = false;

    abstract protected function method(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    /**
     * Set the id that correlates this request with its result event.
     */
    public function id(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getId(): string
    {
        return $this->id ??= (string) Str::uuid();
    }

    /**
     * Send the request to the device. Returns true when it was queued. Every
     * failure, including one reported here, is also delivered as an event.
     */
    public function start(): bool
    {
        if ($this->started) {
            return false;
        }

        $this->started = true;

        if (! function_exists('nativephp_call')) {
            return false;
        }

        $payload = array_merge($this->payload(), ['id' => $this->getId()]);
        $decoded = json_decode((string) nativephp_call($this->method(), json_encode($payload)), true);

        return is_array($decoded) && ($decoded['status'] ?? null) === 'pending';
    }

    public function __destruct()
    {
        if (! $this->started) {
            $this->start();
        }
    }
}
