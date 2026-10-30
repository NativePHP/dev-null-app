import Foundation

// MARK: - Calls Function Namespace

/// One-to-one WebRTC calls.
/// Namespace: "Calls.*"
///
/// Every function returns at once: the work happens on the call's own queue, and what comes of it arrives as events
/// (DescriptionReady, Connected, Ended).
enum CallsFunctions {

    // MARK: - Calls.Start

    /// Opens the microphone and starts a connection.
    /// Parameters:
    ///   - ice_servers: array of {urls, username, credential}
    ///   - is_relay_only: boolean - Only connect through the relay, so neither end sees the other's IP address
    ///   - offer: (optional) string - The partner's offer to answer; without it, this phone makes the offer
    class Start: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let servers = (parameters["ice_servers"] as? [[String: Any]] ?? []).map { server in
                CallManager.IceServer(
                    urls: server["urls"] as? [String] ?? [server["urls"] as? String].compactMap { $0 },
                    username: server["username"] as? String ?? "",
                    credential: server["credential"] as? String ?? ""
                )
            }
            let isRelayOnly = parameters["is_relay_only"] as? Bool ?? true
            let offer = (parameters["offer"] as? String).flatMap { $0.isEmpty ? nil : $0 }

            CallManager.shared.start(iceServers: servers, isRelayOnly: isRelayOnly, offer: offer)

            return ["success": true]
        }
    }

    // MARK: - Calls.Answer

    /// Applies the partner's answer to this phone's offer.
    /// Parameters:
    ///   - sdp: string
    class Answer: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let sdp = parameters["sdp"] as? String else {
                return ["success": false]
            }

            CallManager.shared.answer(sdp: sdp)

            return ["success": true]
        }
    }

    // MARK: - Calls.SetMuted

    /// Parameters:
    ///   - muted: boolean
    class SetMuted: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            CallManager.shared.setMuted(parameters["muted"] as? Bool ?? false)

            return ["success": true]
        }
    }

    // MARK: - Calls.SetSpeaker

    /// Parameters:
    ///   - on: boolean - Loudspeaker when true, earpiece when false
    class SetSpeaker: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            CallManager.shared.setSpeaker(parameters["on"] as? Bool ?? false)

            return ["success": true]
        }
    }

    // MARK: - Calls.End

    /// Hangs up, which closes the call on the partner's side too.
    class End: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            CallManager.shared.end()

            return ["success": true]
        }
    }
}
