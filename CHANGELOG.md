# Changelog

## 1.0.0

### Changed

- Native sources moved to `resources/ios/Sources/` and `resources/android/src/`, the layout the NativePHP plugin marketplace checks for. Apps pick them up on the next build; nothing else changes.
- iOS saves scanned pages one at a time off the main thread, and releases each page's image during recognition before reading the next, so long scans no longer hold every page in memory at once.
- `nativephp.json` names the plugin and its platforms.

### Added

- JavaScript tests (`npm test`) for the promise helpers, run in CI with the PHP suite against both the lowest and the latest supported dependencies.
- README sections for events, Livewire listeners, the camera purpose string override, support and concurrent requests.

## 1.0.0-alpha2

- Published the JavaScript library to npm as `@firstlightui/local-ocr`, versioned with the Composer package.

## 1.0.0-alpha1

- First release: multi-page document capture with VisionKit and the ML Kit Document Scanner, on-device OCR with Apple Vision and ML Kit Text Recognition, a capability check, and the version 1 result contract.
