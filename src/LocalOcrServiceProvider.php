<?php

namespace FirstlightUI\LocalOcr;

use Illuminate\Support\ServiceProvider;

class LocalOcrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LocalOcr::class, fn () => new LocalOcr);
    }
}
