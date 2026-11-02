<?php

namespace Devnull\Calls;

/**
 * A one-to-one WebRTC call on the phone, set up the way dev-null.sh's browsers set theirs up, so the other end can be
 * a browser or another phone. Starting one is asynchronous: the offer or answer to hand the partner arrives as a
 * DescriptionReady event once the phone knows every way to reach it, then Connected or Ended follow.
 */
class Calls
{
    /**
     * Opens the microphone and starts a connection through the given relays. Without an offer, it makes one to send
     * the partner; with the partner's offer, it makes the answer.
     *
     * @param  list<array{urls: list<string>, username: string, credential: string}>  $iceServers
     */
    public function start(array $iceServers, bool $isRelayOnly, ?string $offer = null): bool
    {
        return $this->call('Calls.Start', [
            'ice_servers' => $iceServers,
            'is_relay_only' => $isRelayOnly,
            'offer' => $offer,
        ]);
    }

    /**
     * Applies the partner's answer to the offer this phone made.
     */
    public function answer(string $sdp): bool
    {
        return $this->call('Calls.Answer', ['sdp' => $sdp]);
    }

    public function setMuted(bool $isMuted): bool
    {
        return $this->call('Calls.SetMuted', ['muted' => $isMuted]);
    }

    public function setSpeaker(bool $isOn): bool
    {
        return $this->call('Calls.SetSpeaker', ['on' => $isOn]);
    }

    /**
     * Hangs up. Closing the connection ends the call on the partner's side as well, with no server in between.
     */
    public function end(): bool
    {
        return $this->call('Calls.End');
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function call(string $method, array $parameters = []): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $result = nativephp_call($method, json_encode($parameters, JSON_THROW_ON_ERROR));

        return (bool) (json_decode((string) $result, true)['success'] ?? false);
    }
}
