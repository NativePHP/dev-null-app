import AVFoundation
import Foundation
import WebRTC

/// The phone's end of a call, set up exactly as dev-null.sh's browsers set theirs up (resources/js/lib/call.ts there),
/// so either end can be a browser: the microphone, room for video, and a data channel called "call" whose closing
/// means the other end hung up. The offer or answer only goes out once every way to reach the phone is in it, so the
/// server passes on just two messages.
final class CallManager: NSObject {
    struct IceServer {
        let urls: [String]
        let username: String
        let credential: String
    }

    static let shared = CallManager()

    private static let events = "Devnull\\Calls\\Events\\"

    /// As long as the browser waits for its candidates before sending what it has.
    private static let gatherSeconds = 3.0

    private let queue = DispatchQueue(label: "devnull.calls")
    private lazy var factory: RTCPeerConnectionFactory = {
        RTCInitializeSSL()

        return RTCPeerConnectionFactory(
            encoderFactory: RTCDefaultVideoEncoderFactory(),
            decoderFactory: RTCDefaultVideoDecoderFactory()
        )
    }()

    private var peer: RTCPeerConnection?
    private var channel: RTCDataChannel?
    private var microphone: RTCAudioTrack?
    private var gathered: DispatchSemaphore?

    /// Which call is current. Starting or ending one moves it on, so what a finished call's connection still reports
    /// is ignored.
    private var call = 0

    func start(iceServers: [IceServer], isRelayOnly: Bool, offer: String?) {
        queue.async {
            self.call += 1
            let current = self.call
            self.close()

            guard self.hasMicrophone() else {
                self.ended(current, reason: "microphone")

                return
            }

            do {
                try self.connect(current, iceServers: iceServers, isRelayOnly: isRelayOnly, offer: offer)
            } catch {
                print("DevnullCalls: couldn't start the call: \(error)")
                self.ended(current, reason: "error")
            }
        }
    }

    func answer(sdp: String) {
        queue.async {
            let current = self.call

            guard let peer = self.peer else {
                return
            }

            do {
                try self.setDescription(peer, RTCSessionDescription(type: .answer, sdp: sdp), isLocal: false)
            } catch {
                print("DevnullCalls: couldn't use the answer: \(error)")
                self.ended(current, reason: "failed")
            }
        }
    }

    func setMuted(_ isMuted: Bool) {
        queue.async { self.microphone?.isEnabled = !isMuted }
    }

    func setSpeaker(_ isOn: Bool) {
        queue.async {
            try? AVAudioSession.sharedInstance().overrideOutputAudioPort(isOn ? .speaker : .none)
        }
    }

    func end() {
        queue.async {
            self.call += 1
            self.close()
        }
    }

    private func connect(_ current: Int, iceServers: [IceServer], isRelayOnly: Bool, offer: String?) throws {
        let session = AVAudioSession.sharedInstance()
        try session.setCategory(.playAndRecord, mode: .voiceChat, options: [.allowBluetooth])
        try session.setActive(true)

        let config = RTCConfiguration()
        config.iceServers = iceServers.map { RTCIceServer(urlStrings: $0.urls, username: $0.username, credential: $0.credential) }
        config.sdpSemantics = .unifiedPlan
        // Only through the relay: a direct route would hand each person the other's IP address.
        config.iceTransportPolicy = isRelayOnly ? .relay : .all
        config.continualGatheringPolicy = .gatherOnce

        let gathered = DispatchSemaphore(value: 0)
        self.gathered = gathered

        guard let peer = factory.peerConnection(
            with: config,
            constraints: RTCMediaConstraints(mandatoryConstraints: nil, optionalConstraints: nil),
            delegate: self
        ) else {
            throw CallError.failed("No peer connection")
        }
        self.peer = peer

        let source = factory.audioSource(with: RTCMediaConstraints(mandatoryConstraints: nil, optionalConstraints: nil))
        let microphone = factory.audioTrack(with: source, trackId: "microphone")
        peer.add(microphone, streamIds: ["call"])
        self.microphone = microphone

        let description: RTCSessionDescription

        if let offer {
            try setDescription(peer, RTCSessionDescription(type: .offer, sdp: offer), isLocal: false)
            peer.transceivers.filter { $0.mediaType == .video }.forEach { $0.setDirection(.sendRecv, error: nil) }
            description = try createDescription(peer, isOffer: false)
        } else {
            // Room for video, as the browser leaves it, so a partner on the web can still turn their camera on.
            let video = RTCRtpTransceiverInit()
            video.direction = .sendRecv
            peer.addTransceiver(of: .video, init: video)

            if let channel = peer.dataChannel(forLabel: "call", configuration: RTCDataChannelConfiguration()) {
                watch(channel)
            }

            description = try createDescription(peer, isOffer: true)
        }

        try setDescription(peer, description, isLocal: true)
        _ = gathered.wait(timeout: .now() + Self.gatherSeconds)

        if current == call, let sdp = peer.localDescription?.sdp {
            dispatch("DescriptionReady", ["sdp": sdp])
        }
    }

    /// Asks for the microphone when it isn't allowed yet, and waits for the answer.
    private func hasMicrophone() -> Bool {
        switch AVAudioApplication.shared.recordPermission {
        case .granted:
            return true
        case .denied:
            return false
        default:
            let answered = DispatchSemaphore(value: 0)
            var isGranted = false
            AVAudioApplication.requestRecordPermission { granted in
                isGranted = granted
                answered.signal()
            }
            answered.wait()

            return isGranted
        }
    }

    private func watch(_ opened: RTCDataChannel) {
        channel = opened
        opened.delegate = self
    }

    private func ended(_ current: Int, reason: String) {
        queue.async {
            guard current == self.call else {
                return
            }

            self.call += 1
            self.close()
            self.dispatch("Ended", ["reason": reason])
        }
    }

    private func close() {
        channel?.delegate = nil
        channel?.close()
        channel = nil
        peer?.delegate = nil
        peer?.close()
        peer = nil
        microphone = nil
        gathered?.signal()
        gathered = nil
        try? AVAudioSession.sharedInstance().overrideOutputAudioPort(.none)
        try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
    }

    private func createDescription(_ peer: RTCPeerConnection, isOffer: Bool) throws -> RTCSessionDescription {
        let done = DispatchSemaphore(value: 0)
        var description: RTCSessionDescription?
        var failure: Error?
        let constraints = RTCMediaConstraints(mandatoryConstraints: nil, optionalConstraints: nil)
        let completion: (RTCSessionDescription?, Error?) -> Void = { created, error in
            description = created
            failure = error
            done.signal()
        }

        if isOffer {
            peer.offer(for: constraints, completionHandler: completion)
        } else {
            peer.answer(for: constraints, completionHandler: completion)
        }

        _ = done.wait(timeout: .now() + 10)

        guard let description else {
            throw failure ?? CallError.failed("No session description")
        }

        return description
    }

    private func setDescription(_ peer: RTCPeerConnection, _ description: RTCSessionDescription, isLocal: Bool) throws {
        let done = DispatchSemaphore(value: 0)
        var failure: Error? = CallError.failed("Timed out")
        let completion: (Error?) -> Void = { error in
            failure = error
            done.signal()
        }

        if isLocal {
            peer.setLocalDescription(description, completionHandler: completion)
        } else {
            peer.setRemoteDescription(description, completionHandler: completion)
        }

        _ = done.wait(timeout: .now() + 10)

        if let failure {
            throw failure
        }
    }

    private func dispatch(_ event: String, _ payload: [String: Any]) {
        // Events only reach PHP from the main thread.
        DispatchQueue.main.async {
            LaravelBridge.shared.send?(Self.events + event, payload)
        }
    }

    private enum CallError: Error {
        case failed(String)
    }
}

// MARK: - RTCPeerConnectionDelegate

extension CallManager: RTCPeerConnectionDelegate {
    func peerConnection(_ peerConnection: RTCPeerConnection, didChange newState: RTCIceGatheringState) {
        // Straight from here: the call's queue is the one waiting for it.
        if newState == .complete {
            gathered?.signal()
        }
    }

    func peerConnection(_ peerConnection: RTCPeerConnection, didChange newState: RTCPeerConnectionState) {
        queue.async {
            guard peerConnection === self.peer else {
                return
            }

            switch newState {
            case .connected:
                self.dispatch("Connected", [:])
            case .failed:
                self.ended(self.call, reason: "failed")
            default:
                break
            }
        }
    }

    func peerConnection(_ peerConnection: RTCPeerConnection, didOpen dataChannel: RTCDataChannel) {
        queue.async {
            if peerConnection === self.peer {
                self.watch(dataChannel)
            }
        }
    }

    func peerConnection(_ peerConnection: RTCPeerConnection, didChange stateChanged: RTCSignalingState) {}

    func peerConnection(_ peerConnection: RTCPeerConnection, didAdd stream: RTCMediaStream) {}

    func peerConnection(_ peerConnection: RTCPeerConnection, didRemove stream: RTCMediaStream) {}

    func peerConnectionShouldNegotiate(_ peerConnection: RTCPeerConnection) {}

    func peerConnection(_ peerConnection: RTCPeerConnection, didChange newState: RTCIceConnectionState) {}

    func peerConnection(_ peerConnection: RTCPeerConnection, didGenerate candidate: RTCIceCandidate) {}

    func peerConnection(_ peerConnection: RTCPeerConnection, didRemove candidates: [RTCIceCandidate]) {}
}

// MARK: - RTCDataChannelDelegate

extension CallManager: RTCDataChannelDelegate {
    func dataChannelDidChangeState(_ dataChannel: RTCDataChannel) {
        guard dataChannel.readyState == .closed else {
            return
        }

        queue.async {
            if dataChannel === self.channel {
                self.ended(self.call, reason: "hung_up")
            }
        }
    }

    /// The partner's camera going on or off: this end doesn't show video yet.
    func dataChannel(_ dataChannel: RTCDataChannel, didReceiveMessageWith buffer: RTCDataBuffer) {}
}
