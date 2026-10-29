<?php

use FirstlightUI\LocalOcr\Events\CaptureCancelled;
use FirstlightUI\LocalOcr\Events\CaptureFailed;
use FirstlightUI\LocalOcr\Events\PagesCaptured;
use FirstlightUI\LocalOcr\Events\TextRecognitionFailed;
use FirstlightUI\LocalOcr\Events\TextRecognized;
use FirstlightUI\LocalOcr\Facades\LocalOcr;
use Native\Mobile\Support\NativeCallbacks;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    $this->bridge = Native::fakeBridge();
});

describe('capabilities()', function () {
    it('returns the native capability report', function () {
        $report = [
            'contractVersion' => 1,
            'platform' => 'ios',
            'capture' => ['available' => true, 'engine' => 'visionkit-document-camera', 'reason' => null],
            'ocr' => ['available' => true, 'engine' => 'apple-vision'],
        ];
        $this->bridge->respondTo('LocalOcr.GetCapabilities', $report);

        expect(LocalOcr::capabilities())->toBe($report);
        $this->bridge->assertCalledTimes('LocalOcr.GetCapabilities', 1);
    });

    it('returns null when the native call fails', function () {
        $this->bridge->respondTo('LocalOcr.GetCapabilities', ['status' => 'error', 'code' => 'X', 'message' => 'boom']);

        expect(LocalOcr::capabilities())->toBeNull();
    });
});

describe('capture()', function () {
    it('opens the scanner with a fresh id and registers outcome callbacks under it', function () {
        $capture = LocalOcr::capture()
            ->pagesCaptured(fn (PagesCaptured $event) => null)
            ->captureCancelled(fn (CaptureCancelled $event) => null)
            ->captureFailed(fn (CaptureFailed $event) => null);
        $id = $capture->getId();
        unset($capture);
        LocalOcr::capture()->start();

        [$first, $second] = array_column($this->bridge->callsTo('LocalOcr.Capture'), 'params');

        expect($first)->toBe(['id' => $id])
            ->and($second['id'])->toBeString()->not->toBe($id);

        foreach ([PagesCaptured::class, CaptureCancelled::class, CaptureFailed::class] as $event) {
            expect(NativeCallbacks::resolve($id, $event, consume: false))->not->toBeNull();
        }
    });
});

describe('recognize()', function () {
    it('sends every page path in order, with options and a generated id', function () {
        $captured = [
            ['path' => '/tmp/local-ocr/a/page-1.jpg', 'width' => 1000, 'height' => 1400],
            ['path' => '/tmp/local-ocr/a/page-2.jpg', 'width' => 1000, 'height' => 1400],
        ];

        LocalOcr::recognize($captured)->languages(['en-AU', 'pl-PL'])->fast()->start();
        LocalOcr::recognize('/data/single.jpg')->start();

        [$first, $second] = array_column($this->bridge->callsTo('LocalOcr.Recognize'), 'params');

        expect($first)->toMatchArray([
            'languages' => ['en-AU', 'pl-PL'],
            'recognitionLevel' => 'fast',
            'paths' => ['/tmp/local-ocr/a/page-1.jpg', '/tmp/local-ocr/a/page-2.jpg'],
        ])
            ->and($first['id'])->toBeString()->not->toBeEmpty()
            ->and($second['paths'])->toBe(['/data/single.jpg'])
            ->and($second)->not->toHaveKey('recognitionLevel')
            ->and($second['id'])->toBeString()->not->toBe($first['id']);
    });

    it('reports whether the device queued the request', function () {
        $this->bridge->respondTo('LocalOcr.Recognize', ['status' => 'pending', 'id' => 'a']);
        expect(LocalOcr::recognize('/a.jpg')->id('a')->start())->toBeTrue();

        $this->bridge->respondTo('LocalOcr.Recognize', ['status' => 'error', 'code' => 'FILE_NOT_FOUND', 'message' => 'missing']);
        expect(LocalOcr::recognize('/b.jpg')->id('b')->start())->toBeFalse();
    });

    it('starts once, including when the builder is simply dropped', function () {
        $pending = LocalOcr::recognize('/a.jpg')->id('once');
        $pending->start();
        $pending->start();
        unset($pending);

        LocalOcr::recognize('/b.jpg')->id('dropped');

        $this->bridge->assertCalledTimes('LocalOcr.Recognize', 2);
        $this->bridge->assertCalled('LocalOcr.Recognize', fn (array $p) => $p['id'] === 'dropped');
    });

    it('registers result callbacks under the request id', function () {
        LocalOcr::recognize('/a.jpg')
            ->id('with-callbacks')
            ->textRecognized(fn (TextRecognized $event) => null)
            ->textRecognitionFailed(fn (TextRecognitionFailed $event) => null);

        expect(NativeCallbacks::resolve('with-callbacks', TextRecognized::class, consume: false))->not->toBeNull()
            ->and(NativeCallbacks::resolve('with-callbacks', TextRecognitionFailed::class, consume: false))->not->toBeNull();
    });
});

describe('discard()', function () {
    it('sends the captured page paths and returns the deleted count', function () {
        $this->bridge->respondTo('LocalOcr.Discard', ['deleted' => 2]);

        $deleted = LocalOcr::discard([
            ['path' => '/tmp/local-ocr/a/page-1.jpg', 'width' => 1, 'height' => 1],
            '/tmp/local-ocr/a/page-2.jpg',
        ]);

        expect($deleted)->toBe(2);
        $this->bridge->assertCalled('LocalOcr.Discard', fn (array $p) => $p === [
            'paths' => ['/tmp/local-ocr/a/page-1.jpg', '/tmp/local-ocr/a/page-2.jpg'],
        ]);
    });
});
