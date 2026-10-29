<?php

namespace Tests;

use FirstlightUI\LocalOcr\LocalOcrServiceProvider;
use Native\Mobile\NativeServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            NativeServiceProvider::class,
            LocalOcrServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('nativephp.app_id', 'com.test.app');
        $app['config']->set('nativephp.version', '1.0.0');
        $app['config']->set('nativephp.version_code', 1);
        $app['config']->set('app.name', 'Test App');
    }
}
