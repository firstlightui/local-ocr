/** Normalized to 0..1 of the upright image, origin top-left. */
export interface BoundingBox {
    x: number;
    y: number;
    width: number;
    height: number;
}

export interface RecognizedWord {
    text: string;
    /** 0..1, or null when the engine does not score it. iOS repeats the line's confidence. */
    confidence: number | null;
    boundingBox: BoundingBox | null;
}

export interface RecognizedLine {
    text: string;
    confidence: number | null;
    boundingBox: BoundingBox | null;
    words: RecognizedWord[];
}

export interface RecognizedBlock {
    text: string;
    boundingBox: BoundingBox | null;
    /** iOS reports one line per block; Android groups lines into paragraphs. */
    lines: RecognizedLine[];
}

export interface RecognizedPage {
    /** 1-based, in the order the pages were passed. */
    page: number;
    path: string;
    /** Pixel size of the upright image the boxes refer to (Android may downsample very large images). */
    image: { width: number; height: number };
    /** Block texts joined by newlines. */
    text: string;
    blocks: RecognizedBlock[];
    durationMs: number;
}

export interface RecognitionResult {
    contractVersion: 1;
    engine: 'apple-vision' | 'mlkit-text-recognition';
    /** Page texts joined by blank lines. */
    text: string;
    pages: RecognizedPage[];
    durationMs: number;
}

export interface CapturedPage {
    /** JPEG in the app's private temporary storage. */
    path: string;
    width: number;
    height: number;
}

export interface Capabilities {
    contractVersion: 1;
    platform: 'ios' | 'android';
    osVersion: string;
    deviceModel: string;
    totalMemoryBytes: number;
    capture: {
        available: boolean;
        engine: 'visionkit-document-camera' | 'mlkit-document-scanner';
        reason: string | null;
    };
    ocr: {
        available: boolean;
        engine: 'apple-vision' | 'mlkit-text-recognition';
        onDevice: true;
        /** BCP 47 codes on iOS; null on Android, which reads all Latin-script text. */
        languages: string[] | null;
        recognitionLevels: Array<'accurate' | 'fast'>;
    };
}

export interface RecognizeOptions {
    id?: string;
    /** iOS only: BCP 47 codes in priority order. */
    languages?: string[];
    /** iOS only. Defaults to 'accurate'. */
    recognitionLevel?: 'accurate' | 'fast';
}

export type Pages = string | CapturedPage | Array<string | CapturedPage>;

export type FailureCode =
    | 'INVALID_PARAMETERS'
    | 'FILE_NOT_FOUND'
    | 'DECODE_FAILED'
    | 'RECOGNITION_FAILED'
    | 'CAPTURE_UNAVAILABLE'
    | 'CAPTURE_FAILED'
    | 'SAVE_FAILED';

export declare class LocalOcrError extends Error {
    code: FailureCode | string;
}

export declare const Events: {
    PagesCaptured: string;
    CaptureCancelled: string;
    CaptureFailed: string;
    TextRecognized: string;
    TextRecognitionFailed: string;
};

export declare function GetCapabilities(): Promise<Capabilities>;
export declare function Capture(options?: { id?: string }): Promise<{ status: 'pending'; id: string }>;
export declare function Discard(pages: Pages): Promise<{ deleted: number }>;
export declare function Recognize(pages: Pages, options?: RecognizeOptions): Promise<{ status: 'pending'; id: string }>;
export declare function capturePages(): Promise<CapturedPage[] | null>;
export declare function recognizeText(pages: Pages, options?: RecognizeOptions): Promise<RecognitionResult>;

declare const LocalOcr: {
    GetCapabilities: typeof GetCapabilities;
    Capture: typeof Capture;
    Discard: typeof Discard;
    Recognize: typeof Recognize;
    capturePages: typeof capturePages;
    recognizeText: typeof recognizeText;
    Events: typeof Events;
};

export { LocalOcr };
export default LocalOcr;
