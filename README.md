# Local OCR for NativePHP Mobile

On-device document capture and text recognition for NativePHP Mobile v4.

- **Capture** opens the system document scanner (VisionKit on iOS, ML Kit Document Scanner on Android). The user scans as many pages as they need. Pages are saved as JPEGs in the app's private temporary storage.
- **OCR** runs entirely on the phone: Apple Vision on iOS, ML Kit Text Recognition (Latin script, bundled model) on Android. It returns structured text per page: blocks, lines and words with confidence scores and bounding boxes.
- **Capability check** reports whether capture and OCR are available on the device.

No image or text leaves the device. Interpreting the text belongs in your app, or in an AI plugin for on-device model work.

Capture and recognition are asynchronous: the call returns straight away and the outcome arrives as an event.

## Installation

```bash
composer require firstlightui/local-ocr
php artisan vendor:publish --tag=nativephp-plugins-provider   # first plugin only
php artisan native:plugin:register firstlightui/local-ocr
```

Rebuild the app (`php artisan native:run`) after installing.

## Requirements

| | |
|---|---|
| iOS | 18.0+. Capture needs a real device; VisionKit's document camera doesn't run in the Simulator. |
| Android | API 26+. Capture needs Google Play services. |
| Permissions | iOS adds `NSCameraUsageDescription`. Android needs none: the scanner runs in Google Play services. |

Built and tested with Xcode 27.

Android dependencies added to the app: `com.google.mlkit:text-recognition:16.0.1`, `com.google.android.gms:play-services-mlkit-document-scanner:16.0.0`, `androidx.exifinterface:exifinterface:1.3.7`.

## Usage (PHP)

### Check capabilities

```php
use FirstlightUI\LocalOcr\Facades\LocalOcr;

$capabilities = LocalOcr::capabilities(); // null off-device or on failure

$canScan = $capabilities['capture']['available'] ?? false;
```

### Capture pages, recognize them, then discard them

```php
use FirstlightUI\LocalOcr\Events\PagesCaptured;
use FirstlightUI\LocalOcr\Events\TextRecognized;
use FirstlightUI\LocalOcr\Facades\LocalOcr;

public function scan(): void
{
    LocalOcr::capture()
        ->pagesCaptured(function (PagesCaptured $event) {
            LocalOcr::recognize($event->pages)
                ->textRecognized(function (TextRecognized $result) use ($event) {
                    $this->text = $result->result['text'];
                    LocalOcr::discard($event->pages);
                });
        })
        ->captureCancelled(fn () => $this->status = 'Cancelled');
}
```

Each builder starts automatically when it goes out of scope. You can also listen with `#[On]`; listener parameters bind by name to the event payload:

```php
use FirstlightUI\LocalOcr\Events\TextRecognized;
use Native\Mobile\Attributes\On;

#[On(TextRecognized::class)]
public function onText(string $id, array $result): void
{
    $this->pages = $result['pages'];
}
```

| Method | Returns |
|---|---|
| `LocalOcr::capabilities()` | Capability report array, or `null`. |
| `LocalOcr::capture()` | `PendingCapture`: `->pagesCaptured()`, `->captureCancelled()`, `->captureFailed()`. |
| `LocalOcr::recognize($pages)` | `PendingRecognition`: `->textRecognized()`, `->textRecognitionFailed()`, `->languages([...])` (iOS), `->fast()` (iOS). |
| `LocalOcr::discard($pages)` | Number of files deleted. Only files this plugin captured are touched. |

`$pages` can be a path, a list of paths, or the `pages` array from `PagesCaptured`. Both builders also have `id()`, `getId()` and `start()`.

Captured pages stay until you discard them. Anything left over is deleted on the next capture after 24 hours. iOS also clears its temporary directory when it needs space.

## Usage (JavaScript)

Install the bundled JS package from the Composer vendor directory:

```bash
npm install ./vendor/firstlightui/local-ocr
```

```js
import { GetCapabilities, capturePages, recognizeText, Discard, LocalOcrError } from '@firstlightui/local-ocr';

const capabilities = await GetCapabilities();

const pages = await capturePages(); // null if the user cancelled
if (pages) {
    try {
        const result = await recognizeText(pages, { languages: ['en-AU'] });
        console.log(result.text);
    } finally {
        await Discard(pages);
    }
}
```

`capturePages()` and `recognizeText()` resolve when the matching event arrives and reject with a `LocalOcrError` carrying `code`. `Capture()` and `Recognize()` are the raw bridge calls, if you want to handle the `native-event` DOM events yourself; their names are in `Events`. TypeScript types for every contract are in `resources/js/index.d.ts`.

## Contracts

The capability report and recognition result carry `contractVersion: 1`. Fields are only added within a version; any breaking change bumps it.

### Capabilities

```json
{
    "contractVersion": 1,
    "platform": "ios",
    "osVersion": "27.0.0",
    "deviceModel": "iPhone17,1",
    "totalMemoryBytes": 8589934592,
    "capture": {
        "available": true,
        "engine": "visionkit-document-camera",
        "reason": null
    },
    "ocr": {
        "available": true,
        "engine": "apple-vision",
        "onDevice": true,
        "languages": ["en-US", "fr-FR", "..."],
        "recognitionLevels": ["accurate", "fast"]
    }
}
```

- `capture.engine`: `visionkit-document-camera` or `mlkit-document-scanner`. `capture.reason` is `documentCameraUnsupported` or `googlePlayServicesUnavailable` when capture isn't available.
- `ocr.engine`: `apple-vision` or `mlkit-text-recognition`. `ocr.languages` is `null` on Android, which reads all Latin-script text.

### Captured pages (`PagesCaptured`)

Payload: `{ "id": "…", "pages": [{ "path": "…/local-ocr/<id>/page-1.jpg", "width": 2480, "height": 3508 }] }`, in scan order.

### Recognition result (`TextRecognized`)

Payload: `{ "id": "…", "result": { … } }`

```json
{
    "contractVersion": 1,
    "engine": "mlkit-text-recognition",
    "text": "MRN 1234567\nCITIZEN, Jane\n\nWard 4B",
    "pages": [
        {
            "page": 1,
            "path": "/data/user/0/…/cache/local-ocr/…/page-1.jpg",
            "image": { "width": 2480, "height": 3508 },
            "text": "MRN 1234567\nCITIZEN, Jane",
            "blocks": [
                {
                    "text": "MRN 1234567\nCITIZEN, Jane",
                    "boundingBox": { "x": 0.08, "y": 0.11, "width": 0.41, "height": 0.07 },
                    "lines": [
                        {
                            "text": "MRN 1234567",
                            "confidence": 0.98,
                            "boundingBox": { "x": 0.08, "y": 0.11, "width": 0.31, "height": 0.03 },
                            "words": [
                                { "text": "MRN", "confidence": 0.99, "boundingBox": { "x": 0.08, "y": 0.11, "width": 0.09, "height": 0.03 } }
                            ]
                        }
                    ]
                }
            ],
            "durationMs": 412
        }
    ],
    "durationMs": 803
}
```

- `pages` follows the order of the paths you passed; `page` is 1-based.
- `text` is the page texts joined by blank lines. Each page's `text` is its block texts joined by newlines.
- Boxes are normalized to 0–1 of the upright image (EXIF orientation applied), origin top-left. A box may be `null` if the engine can't locate it.
- `confidence` is 0–1, or `null` if the engine doesn't score it. iOS scores lines only, so each word repeats its line's confidence.
- iOS has no paragraph grouping: each line is its own block. Android groups lines into blocks.
- Android downsamples images larger than 4096 px on their long side. `image` reports the size that was read.

### Failures

`TextRecognitionFailed` and `CaptureFailed` carry `{ "id": "…", "code": "…", "message": "…" }`. `CaptureCancelled` carries `{ "id": "…" }`.

| Event | Codes |
|---|---|
| `TextRecognitionFailed` | `INVALID_PARAMETERS`, `FILE_NOT_FOUND`, `DECODE_FAILED`, `RECOGNITION_FAILED` |
| `CaptureFailed` | `INVALID_PARAMETERS`, `CAPTURE_UNAVAILABLE`, `CAPTURE_FAILED`, `SAVE_FAILED` |

A recognition failure on any page fails the whole request, and the message names the page. Input errors are also returned synchronously, so `start()` returns `false`.

## Testing

```bash
composer install
vendor/bin/pest
```

The suite checks the PHP-to-native contract with NativePHP's `FakeBridge`, and checks that the manifest, Kotlin, Swift and JS agree on function and event names. Native capture and recognition can only be verified on a device or simulator.

## License

MIT
