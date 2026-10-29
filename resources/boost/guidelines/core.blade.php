## firstlightui/local-ocr

On-device document capture (VisionKit / ML Kit Document Scanner) and OCR (Apple Vision / ML Kit) for NativePHP Mobile. It returns structured text only; interpreting it is the app's job.

- `LocalOcr::capabilities()` returns an array (`contractVersion`, `platform`, `capture`, `ocr`) or null; check `capture.available` before calling `capture()`.
- `LocalOcr::capture()`, `LocalOcr::recognize($pages)` are asynchronous. They return builders that start on destruction. Outcomes arrive as events, never as return values.
- `PagesCaptured` gives `pages`: `[{path, width, height}]`, JPEGs in private temporary storage. Pass them straight to `recognize()`, and call `LocalOcr::discard($pages)` when done.
- Recognition results follow contract version 1: `result.text`, then `result.pages[].blocks[].lines[].words[]`, each with `text`, `confidence` (0–1 or null) and `boundingBox` (normalized 0–1, origin top-left).
- iOS-only recognition options: `->languages([...])`, `->fast()`.

@verbatim
<code-snippet name="Capture, recognize and discard" lang="php">
use FirstlightUI\LocalOcr\Events\PagesCaptured;
use FirstlightUI\LocalOcr\Events\TextRecognized;
use FirstlightUI\LocalOcr\Facades\LocalOcr;

LocalOcr::capture()
    ->pagesCaptured(function (PagesCaptured $event) {
        LocalOcr::recognize($event->pages)
            ->textRecognized(function (TextRecognized $result) use ($event) {
                $this->text = $result->result['text'];
                LocalOcr::discard($event->pages);
            });
    })
    ->captureCancelled(fn () => $this->status = 'Cancelled');
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Listen for results by attribute" lang="php">
use FirstlightUI\LocalOcr\Events\TextRecognized;
use Native\Mobile\Attributes\On;

#[On(TextRecognized::class)]
public function onText(string $id, array $result): void
{
    // $result['pages'] holds per-page blocks, lines and words
}
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="JavaScript" lang="javascript">
import { capturePages, recognizeText, Discard } from '@firstlightui/local-ocr';

const pages = await capturePages(); // null if cancelled
const result = await recognizeText(pages); // rejects with LocalOcrError { code, message }
await Discard(pages);
</code-snippet>
@endverbatim

Failure codes: recognition `INVALID_PARAMETERS`, `FILE_NOT_FOUND`, `DECODE_FAILED`, `RECOGNITION_FAILED`; capture `INVALID_PARAMETERS`, `CAPTURE_UNAVAILABLE`, `CAPTURE_FAILED`, `SAVE_FAILED`.
