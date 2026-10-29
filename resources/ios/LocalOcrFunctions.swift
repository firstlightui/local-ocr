import Foundation
import UIKit
import Vision
import VisionKit

/// Multi-page document capture (VisionKit) and on-device OCR (Apple Vision).
/// Namespace: "LocalOcr.*"
enum LocalOcrFunctions {

    static let contractVersion = 1
    static let engine = "apple-vision"
    static let captureEngine = "visionkit-document-camera"
    static let recognizedEvent = "FirstlightUI\\LocalOcr\\Events\\TextRecognized"
    static let failedEvent = "FirstlightUI\\LocalOcr\\Events\\TextRecognitionFailed"
    static let capturedEvent = "FirstlightUI\\LocalOcr\\Events\\PagesCaptured"
    static let captureCancelledEvent = "FirstlightUI\\LocalOcr\\Events\\CaptureCancelled"
    static let captureFailedEvent = "FirstlightUI\\LocalOcr\\Events\\CaptureFailed"
    static let worker = DispatchQueue(label: "dev.firstlightui.local-ocr", qos: .userInitiated)

    /// Captures left behind by the app are deleted on the next capture.
    static let staleCaptureAge: TimeInterval = 24 * 60 * 60

    // MARK: - LocalOcr.GetCapabilities

    /// Returns the capability contract.
    class GetCapabilities: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let request = VNRecognizeTextRequest()
            request.recognitionLevel = .accurate
            let languages = (try? request.supportedRecognitionLanguages()) ?? []
            let os = ProcessInfo.processInfo.operatingSystemVersion
            let canCapture = LocalOcrFunctions.documentCameraSupported()

            return BridgeResponse.success(data: [
                "contractVersion": LocalOcrFunctions.contractVersion,
                "platform": "ios",
                "osVersion": "\(os.majorVersion).\(os.minorVersion).\(os.patchVersion)",
                "deviceModel": LocalOcrFunctions.hardwareModel(),
                "totalMemoryBytes": ProcessInfo.processInfo.physicalMemory,
                "capture": [
                    "available": canCapture,
                    "engine": LocalOcrFunctions.captureEngine,
                    "reason": canCapture ? NSNull() as Any : "documentCameraUnsupported",
                ] as [String: Any],
                "ocr": [
                    "available": true,
                    "engine": LocalOcrFunctions.engine,
                    "onDevice": true,
                    "languages": languages,
                    "recognitionLevels": ["accurate", "fast"],
                ] as [String: Any],
            ])
        }
    }

    // MARK: - LocalOcr.Capture

    /// Opens the system document camera. Parameters:
    ///   - id: string (required) - correlates the result event
    /// Returns { status: "pending", id } and later dispatches PagesCaptured,
    /// CaptureCancelled or CaptureFailed. Pages are JPEGs in the app's
    /// private temporary directory with complete file protection.
    class Capture: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let id = parameters["id"] as? String ?? ""
            if id.isEmpty {
                return LocalOcrFunctions.fail(LocalOcrFunctions.captureFailedEvent, id, "INVALID_PARAMETERS", "id is required")
            }

            if !LocalOcrFunctions.documentCameraSupported() {
                return LocalOcrFunctions.fail(
                    LocalOcrFunctions.captureFailedEvent, id, "CAPTURE_UNAVAILABLE",
                    "The document camera isn't supported on this device"
                )
            }

            LocalOcrFunctions.worker.async { LocalOcrFunctions.purgeStaleCaptures() }
            DispatchQueue.main.async { DocumentCaptureSession.start(id: id) }

            return BridgeResponse.success(data: ["status": "pending", "id": id])
        }
    }

    // MARK: - LocalOcr.Discard

    /// Deletes captured pages. Parameters:
    ///   - paths: [string] - page paths from PagesCaptured
    /// Only files inside this plugin's capture directory are deleted.
    class Discard: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let root = LocalOcrFunctions.captureRoot().standardizedFileURL.resolvingSymlinksInPath().path
            let manager = FileManager.default
            var deleted = 0

            for path in LocalOcrFunctions.paths(from: parameters["paths"]) {
                let file = URL(fileURLWithPath: path).standardizedFileURL.resolvingSymlinksInPath()
                guard file.path.hasPrefix(root + "/") else { continue }

                if (try? manager.removeItem(at: file)) != nil {
                    deleted += 1
                }
                let directory = file.deletingLastPathComponent()
                if directory.path != root, (try? manager.contentsOfDirectory(atPath: directory.path))?.isEmpty == true {
                    try? manager.removeItem(at: directory)
                }
            }

            return BridgeResponse.success(data: ["deleted": deleted])
        }
    }

    // MARK: - LocalOcr.Recognize

    /// Parameters:
    ///   - id: string (required) - correlates the result event
    ///   - paths: [string] (required) - absolute paths or file:// URLs, in page order
    ///   - languages: [string] (optional) - BCP 47 codes in priority order
    ///   - recognitionLevel: "accurate" | "fast" (optional, default "accurate")
    /// Returns { status: "pending", id } and later dispatches TextRecognized
    /// or TextRecognitionFailed.
    class Recognize: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let id = parameters["id"] as? String ?? ""
            let paths = LocalOcrFunctions.paths(from: parameters["paths"])

            if id.isEmpty || paths.isEmpty {
                return LocalOcrFunctions.fail(LocalOcrFunctions.failedEvent, id, "INVALID_PARAMETERS", "id and at least one path are required")
            }

            if let missing = paths.first(where: { !FileManager.default.fileExists(atPath: $0) }) {
                return LocalOcrFunctions.fail(LocalOcrFunctions.failedEvent, id, "FILE_NOT_FOUND", "No image file at \(missing)")
            }

            let languages = parameters["languages"] as? [String] ?? []
            let level: VNRequestTextRecognitionLevel =
                (parameters["recognitionLevel"] as? String) == "fast" ? .fast : .accurate

            LocalOcrFunctions.worker.async {
                LocalOcrFunctions.recognize(id: id, paths: paths, languages: languages, level: level)
            }

            return BridgeResponse.success(data: ["status": "pending", "id": id])
        }
    }

    /// VisionKit is main-actor isolated; bridge calls arrive on the PHP thread.
    static func documentCameraSupported() -> Bool {
        Thread.isMainThread
            ? MainActor.assumeIsolated { VNDocumentCameraViewController.isSupported }
            : DispatchQueue.main.sync { MainActor.assumeIsolated { VNDocumentCameraViewController.isSupported } }
    }

    // MARK: - Capture storage

    static func captureRoot() -> URL {
        FileManager.default.temporaryDirectory.appendingPathComponent("local-ocr", isDirectory: true)
    }

    static func purgeStaleCaptures() {
        let manager = FileManager.default
        let cutoff = Date().addingTimeInterval(-staleCaptureAge)
        let captures = (try? manager.contentsOfDirectory(
            at: captureRoot(), includingPropertiesForKeys: [.contentModificationDateKey]
        )) ?? []

        for capture in captures {
            let modified = (try? capture.resourceValues(forKeys: [.contentModificationDateKey]))?.contentModificationDate
            if let modified, modified < cutoff {
                try? manager.removeItem(at: capture)
            }
        }
    }

    static func savePages(id: String, scan: VNDocumentCameraScan) {
        let directory = captureRoot().appendingPathComponent(id, isDirectory: true)
        let images = (0..<scan.pageCount).map { scan.imageOfPage(at: $0) }

        worker.async {
            do {
                try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
                var pages: [[String: Any]] = []

                for (index, image) in images.enumerated() {
                    guard let data = image.jpegData(compressionQuality: 0.9) else {
                        throw NSError(domain: "LocalOcr", code: 1, userInfo: [
                            NSLocalizedDescriptionKey: "Could not encode page \(index + 1)",
                        ])
                    }
                    let file = directory.appendingPathComponent("page-\(index + 1).jpg")
                    try data.write(to: file, options: [.atomic, .completeFileProtection])
                    pages.append([
                        "path": file.path,
                        "width": Int(image.size.width * image.scale),
                        "height": Int(image.size.height * image.scale),
                    ])
                }

                dispatch(capturedEvent, ["id": id, "pages": pages])
            } catch {
                try? FileManager.default.removeItem(at: directory)
                _ = fail(captureFailedEvent, id, "SAVE_FAILED", error.localizedDescription)
            }
        }
    }

    // MARK: - Recognition

    static func recognize(id: String, paths: [String], languages: [String], level: VNRequestTextRecognitionLevel) {
        let startedAt = Date()
        var pages: [[String: Any]] = []

        for (index, path) in paths.enumerated() {
            let pageStartedAt = Date()

            guard let image = UIImage(contentsOfFile: path), let cgImage = image.cgImage else {
                _ = fail(failedEvent, id, "DECODE_FAILED", "Could not decode page \(index + 1) at \(path)")
                return
            }

            // Vision reports boxes in the oriented (upright) image, so report its size.
            let orientation = CGImagePropertyOrientation(image.imageOrientation)
            let sideways: Set<CGImagePropertyOrientation> = [.left, .leftMirrored, .right, .rightMirrored]
            let width = sideways.contains(orientation) ? cgImage.height : cgImage.width
            let height = sideways.contains(orientation) ? cgImage.width : cgImage.height

            let request = VNRecognizeTextRequest()
            request.recognitionLevel = level
            request.usesLanguageCorrection = level == .accurate
            if !languages.isEmpty {
                request.recognitionLanguages = languages
            }

            do {
                try VNImageRequestHandler(cgImage: cgImage, orientation: orientation, options: [:]).perform([request])
            } catch {
                _ = fail(failedEvent, id, "RECOGNITION_FAILED", "Page \(index + 1): \(error.localizedDescription)")
                return
            }

            // Vision has no block grouping: each recognized line is its own block.
            var blocks: [[String: Any]] = []
            for observation in request.results ?? [] {
                guard let candidate = observation.topCandidates(1).first else { continue }
                let confidence = round(Double(candidate.confidence))
                let line: [String: Any] = [
                    "text": candidate.string,
                    "confidence": confidence,
                    "boundingBox": box(observation.boundingBox),
                    "words": words(in: candidate, confidence: confidence),
                ]
                blocks.append([
                    "text": candidate.string,
                    "boundingBox": box(observation.boundingBox),
                    "lines": [line],
                ])
            }

            pages.append([
                "page": index + 1,
                "path": path,
                "image": ["width": width, "height": height],
                "text": blocks.compactMap { $0["text"] as? String }.joined(separator: "\n"),
                "blocks": blocks,
                "durationMs": Int(Date().timeIntervalSince(pageStartedAt) * 1000),
            ])
        }

        let result: [String: Any] = [
            "contractVersion": contractVersion,
            "engine": engine,
            "text": pages.compactMap { $0["text"] as? String }.joined(separator: "\n\n"),
            "pages": pages,
            "durationMs": Int(Date().timeIntervalSince(startedAt) * 1000),
        ]

        dispatch(recognizedEvent, ["id": id, "result": result])
    }

    /// Vision only scores whole lines, so each word carries its line's confidence.
    static func words(in candidate: VNRecognizedText, confidence: Double) -> [[String: Any]] {
        let text = candidate.string
        guard let pattern = try? NSRegularExpression(pattern: "\\S+") else { return [] }

        return pattern.matches(in: text, range: NSRange(text.startIndex..., in: text)).compactMap { match in
            guard let range = Range(match.range, in: text) else { return nil }
            let rect = (try? candidate.boundingBox(for: range))?.boundingBox

            return [
                "text": String(text[range]),
                "confidence": confidence,
                "boundingBox": rect.map { box($0) as Any } ?? NSNull(),
            ]
        }
    }

    /// Converts Vision's normalized bottom-left-origin rect to the contract's
    /// normalized top-left-origin box.
    static func box(_ rect: CGRect) -> [String: Any] {
        let left = min(max(Double(rect.minX), 0), 1)
        let right = min(max(Double(rect.maxX), 0), 1)
        let top = min(max(1 - Double(rect.maxY), 0), 1)
        let bottom = min(max(1 - Double(rect.minY), 0), 1)

        return [
            "x": round(left),
            "y": round(top),
            "width": round(right - left),
            "height": round(bottom - top),
        ]
    }

    static func round(_ value: Double) -> Double {
        (value * 100_000).rounded() / 100_000
    }

    // MARK: - Capabilities

    static func hardwareModel() -> String {
        // In the Simulator uname reports the host architecture ("arm64").
        if let simulated = ProcessInfo.processInfo.environment["SIMULATOR_MODEL_IDENTIFIER"] {
            return simulated
        }

        var systemInfo = utsname()
        uname(&systemInfo)
        return withUnsafeBytes(of: &systemInfo.machine) { buffer in
            String(decoding: buffer.prefix(while: { $0 != 0 }), as: UTF8.self)
        }
    }

    // MARK: - Helpers

    static func paths(from value: Any?) -> [String] {
        (value as? [Any] ?? []).compactMap { item -> String? in
            guard var path = item as? String, !path.isEmpty else { return nil }
            if path.hasPrefix("file://"), let url = URL(string: path) {
                path = url.path
            }
            return path
        }
    }

    static func fail(_ event: String, _ id: String, _ code: String, _ message: String) -> [String: Any] {
        dispatch(event, ["id": id, "code": code, "message": message])

        return BridgeResponse.error(code: code, message: message)
    }

    /// Events reach the WebView and PHP only when sent on the main thread.
    static func dispatch(_ event: String, _ payload: [String: Any]) {
        DispatchQueue.main.async {
            LaravelBridge.shared.send?(event, payload)
        }
    }
}

// MARK: - Document camera presentation

/// Presents VNDocumentCameraViewController and keeps its delegate alive until
/// the user finishes, cancels or the camera fails.
final class DocumentCaptureSession: NSObject, VNDocumentCameraViewControllerDelegate {
    private static var active: [String: DocumentCaptureSession] = [:]

    private let id: String

    private init(id: String) {
        self.id = id
    }

    static func start(id: String) {
        guard let presenter = topViewController() else {
            _ = LocalOcrFunctions.fail(
                LocalOcrFunctions.captureFailedEvent, id, "CAPTURE_UNAVAILABLE", "No view controller to present the camera from"
            )
            return
        }

        let session = DocumentCaptureSession(id: id)
        active[id] = session

        let camera = VNDocumentCameraViewController()
        camera.delegate = session
        presenter.present(camera, animated: true)
    }

    func documentCameraViewController(_ controller: VNDocumentCameraViewController, didFinishWith scan: VNDocumentCameraScan) {
        if scan.pageCount == 0 {
            LocalOcrFunctions.dispatch(LocalOcrFunctions.captureCancelledEvent, ["id": id])
        } else {
            LocalOcrFunctions.savePages(id: id, scan: scan)
        }
        finish(controller)
    }

    func documentCameraViewControllerDidCancel(_ controller: VNDocumentCameraViewController) {
        LocalOcrFunctions.dispatch(LocalOcrFunctions.captureCancelledEvent, ["id": id])
        finish(controller)
    }

    func documentCameraViewController(_ controller: VNDocumentCameraViewController, didFailWithError error: Error) {
        _ = LocalOcrFunctions.fail(LocalOcrFunctions.captureFailedEvent, id, "CAPTURE_FAILED", error.localizedDescription)
        finish(controller)
    }

    private func finish(_ controller: VNDocumentCameraViewController) {
        controller.dismiss(animated: true)
        Self.active[id] = nil
    }

    private static func topViewController() -> UIViewController? {
        let scenes = UIApplication.shared.connectedScenes.compactMap { $0 as? UIWindowScene }
        let scene = scenes.first(where: { $0.activationState == .foregroundActive }) ?? scenes.first
        let window = scene?.windows.first(where: { $0.isKeyWindow }) ?? scene?.windows.first
        var top = window?.rootViewController

        while let presented = top?.presentedViewController, !presented.isBeingDismissed {
            top = presented
        }

        return top
    }
}

private extension CGImagePropertyOrientation {
    init(_ orientation: UIImage.Orientation) {
        switch orientation {
        case .up: self = .up
        case .upMirrored: self = .upMirrored
        case .down: self = .down
        case .downMirrored: self = .downMirrored
        case .left: self = .left
        case .leftMirrored: self = .leftMirrored
        case .right: self = .right
        case .rightMirrored: self = .rightMirrored
        @unknown default: self = .up
        }
    }
}
