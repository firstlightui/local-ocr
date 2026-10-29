/**
 * Local OCR for NativePHP Mobile web views (Inertia + Vue/React, plain JS).
 *
 * @example
 * import { GetCapabilities, capturePages, recognizeText, Discard } from '@firstlightui/local-ocr';
 *
 * const capabilities = await GetCapabilities();
 * const pages = await capturePages();          // null if the user cancelled
 * const result = await recognizeText(pages);
 * await Discard(pages);
 */

const baseUrl = '/_native/api/call';

export const Events = {
    PagesCaptured: 'FirstlightUI\\LocalOcr\\Events\\PagesCaptured',
    CaptureCancelled: 'FirstlightUI\\LocalOcr\\Events\\CaptureCancelled',
    CaptureFailed: 'FirstlightUI\\LocalOcr\\Events\\CaptureFailed',
    TextRecognized: 'FirstlightUI\\LocalOcr\\Events\\TextRecognized',
    TextRecognitionFailed: 'FirstlightUI\\LocalOcr\\Events\\TextRecognitionFailed',
};

export class LocalOcrError extends Error {
    constructor(code, message) {
        super(message);
        this.name = 'LocalOcrError';
        this.code = code;
    }
}

async function bridgeCall(method, params = {}) {
    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ method, params }),
    });

    const body = await response.json();

    if (body.status === 'error') {
        throw new LocalOcrError(body.code || 'BRIDGE_ERROR', body.message || 'Native call failed');
    }

    return body.data;
}

const toPaths = (pages) => [].concat(pages).map((page) => (typeof page === 'string' ? page : page.path));

/**
 * Start a native operation and settle with the event that carries its id.
 * `outcomes` maps event names to (payload) => ({ value } | { error }).
 */
function awaitOutcome(start, id, outcomes) {
    return new Promise((resolve, reject) => {
        const listener = ({ detail }) => {
            const outcome = outcomes[detail?.event];

            if (!outcome || detail.payload?.id !== id) {
                return;
            }

            document.removeEventListener('native-event', listener);
            const { value, error } = outcome(detail.payload);
            error ? reject(error) : resolve(value);
        };

        // Listen before calling: input errors are reported as a failure event
        // as well as a rejected bridge call.
        document.addEventListener('native-event', listener);

        start().catch((error) => {
            document.removeEventListener('native-event', listener);
            reject(error);
        });
    });
}

const failure = (payload) => ({ error: new LocalOcrError(payload.code, payload.message) });

/**
 * Report the device's capture and OCR capabilities.
 *
 * @returns {Promise<import('./index').Capabilities>}
 */
export async function GetCapabilities() {
    return bridgeCall('LocalOcr.GetCapabilities');
}

/**
 * Open the document scanner. Resolves to { status: 'pending', id }; pages
 * arrive later as a PagesCaptured event. Prefer capturePages().
 *
 * @param {{ id?: string }} [options]
 */
export async function Capture(options = {}) {
    return bridgeCall('LocalOcr.Capture', { id: options.id || crypto.randomUUID() });
}

/**
 * Delete captured pages.
 *
 * @param {string | import('./index').CapturedPage | Array<string | import('./index').CapturedPage>} pages
 * @returns {Promise<{ deleted: number }>}
 */
export async function Discard(pages) {
    return bridgeCall('LocalOcr.Discard', { paths: toPaths(pages) });
}

/**
 * Queue recognition. Resolves to { status: 'pending', id }; the result
 * arrives later as a TextRecognized or TextRecognitionFailed event. Prefer
 * recognizeText().
 *
 * @param {string | import('./index').CapturedPage | Array<string | import('./index').CapturedPage>} pages
 * @param {import('./index').RecognizeOptions} [options]
 */
export async function Recognize(pages, options = {}) {
    return bridgeCall('LocalOcr.Recognize', { ...options, paths: toPaths(pages), id: options.id || crypto.randomUUID() });
}

/**
 * Scan pages and resolve with them once the user finishes, or with null if
 * they cancel. Rejects with a LocalOcrError if the scanner fails.
 *
 * @returns {Promise<import('./index').CapturedPage[] | null>}
 */
export function capturePages() {
    const id = crypto.randomUUID();

    return awaitOutcome(() => Capture({ id }), id, {
        [Events.PagesCaptured]: (payload) => ({ value: payload.pages }),
        [Events.CaptureCancelled]: () => ({ value: null }),
        [Events.CaptureFailed]: failure,
    });
}

/**
 * Recognize text on one or more pages and resolve with the result.
 * Rejects with a LocalOcrError carrying the failure code.
 *
 * @param {string | import('./index').CapturedPage | Array<string | import('./index').CapturedPage>} pages
 * @param {import('./index').RecognizeOptions} [options]
 * @returns {Promise<import('./index').RecognitionResult>}
 */
export function recognizeText(pages, options = {}) {
    const id = options.id || crypto.randomUUID();

    return awaitOutcome(() => Recognize(pages, { ...options, id }), id, {
        [Events.TextRecognized]: (payload) => ({ value: payload.result }),
        [Events.TextRecognitionFailed]: failure,
    });
}

export const LocalOcr = { GetCapabilities, Capture, Discard, Recognize, capturePages, recognizeText, Events };

export default LocalOcr;
