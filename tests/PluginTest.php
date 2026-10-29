<?php

use Native\Mobile\Events\Contracts\DispatchableFromNative;

beforeEach(function () {
    $this->root = dirname(__DIR__);
    $this->manifest = json_decode(file_get_contents($this->root.'/nativephp.json'), true, flags: JSON_THROW_ON_ERROR);
    $this->kotlin = file_get_contents($this->root.'/resources/android/LocalOcrFunctions.kt');
    $this->swift = file_get_contents($this->root.'/resources/ios/LocalOcrFunctions.swift');
    $this->js = file_get_contents($this->root.'/resources/js/index.js');
});

it('implements every bridge function on both platforms and in JS', function () {
    foreach ($this->manifest['bridge_functions'] as $function) {
        $android = explode('.', $function['android']);
        $class = end($android);

        expect(implode('.', array_slice($android, 0, -2)))->toBe('dev.firstlightui.plugins.local_ocr')
            ->and($this->kotlin)->toContain("package dev.firstlightui.plugins.local_ocr\n")
            ->and($this->kotlin)->toContain("class {$class}(")
            ->and($function['ios'])->toBe("LocalOcrFunctions.{$class}")
            ->and($this->swift)->toContain("class {$class}: BridgeFunction")
            ->and($this->js)->toContain("'{$function['name']}'");
    }
});

it('dispatches exactly the declared events, which PHP accepts from native code', function () {
    $declared = $this->manifest['events'];

    foreach ($declared as $event) {
        expect(class_exists($event))->toBeTrue()
            ->and(is_subclass_of($event, DispatchableFromNative::class))->toBeTrue();
    }

    // Native sources escape each backslash once inside string literals.
    $pattern = '/"(FirstlightUI(?:\\\\\\\\\w+)+)"/';
    foreach (['kotlin', 'swift'] as $source) {
        preg_match_all($pattern, $this->{$source}, $matches);
        $used = array_unique(array_map(fn ($name) => str_replace('\\\\', '\\', $name), $matches[1]));

        expect($used)->toEqualCanonicalizing($declared);
    }

    preg_match_all("/'(FirstlightUI(?:\\\\\\\\\\w+)+)'/", $this->js, $matches);
    expect(array_map(fn ($name) => str_replace('\\\\', '\\', $name), $matches[1]))->toEqualCanonicalizing($declared);
});

it('sets the minimum platform versions the marketplace requires', function () {
    expect($this->manifest['android']['min_version'])->toBeInt()
        ->and($this->manifest['ios']['min_version'])->toBeString();
});
