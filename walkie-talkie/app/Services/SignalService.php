<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Handles every signaling message type:
 * hello, poll, ptt_request, ptt_release, signal, leave
 *
 * "Floor control": room['speaker'] is the single person allowed to talk.
 * Because every method runs inside RoomService::transaction() (exclusive lock),
 * two simultaneous ptt_request calls are processed one after the other - the
 * first one wins, the second one gets "busy".
 */
final class SignalService
{
    private RoomService $rooms;

    public function __construct(?RoomService $rooms = null)
    {
        $this->rooms = $rooms ?? new RoomService();
    }

    /** @param array{pid:string,nick:string,ch:string} $claims */
    public function handle(string $type, array $claims, array $msg): array
    {
        return match ($type) {
            'hello'       => $this->hello($claims),
            'poll'        => $this->poll($claims, max(0, (int) ($msg['since'] ?? 0))),
            'ptt_request' => $this->pttRequest($claims),
            'ptt_release' => $this->pttRelease($claims),
            'signal'      => $this->signal($claims, $msg),
            'leave'       => $this->leave($claims),
            default       => throw new SignalException('bad_request', 'Unknown message type.', 400),
        };
    }

    private function hello(array $c): array
    {
        $max = (int) Config::get('max_peers', 8);
        $result = $this->rooms->transaction($c['ch'], function (array &$room, int $now, bool &$dirty) use ($c, $max): array {
            $pid = $c['pid'];
            $existing = isset($room['peers'][$pid]);
            if (!$existing && count($room['peers']) >= $max) {
                throw new SignalException('room_full', "This channel is currently full.\nMaximum $max users are allowed.", 409);
            }
            // A freshly loaded page can never be transmitting: free the floor if it was ours.
            if ($room['speaker'] && $room['speaker']['peer'] === $pid) {
                $room['speaker'] = null;
                $this->rooms->emit($room, 'speaker_stopped', $pid, null, ['nick' => $c['nick'], 'reason' => 'rejoin'], $now);
            }
            if (!$existing) {
                $this->rooms->emit($room, 'peer_joined', $pid, null, ['nick' => $c['nick']], $now);
            }
            $room['peers'][$pid] = [
                'nick'      => $c['nick'],
                'joined'    => $existing ? $room['peers'][$pid]['joined'] : $now,
                'last_seen' => $now,
                'cursor'    => $room['seq'],
            ];
            $dirty = true;
            return [
                'ok'       => true,
                'rejoined' => $existing,
                'seq'      => $room['seq'],
                'state'    => $this->rooms->snapshot($room, $now),
                'config'   => ['floor_timeout_ms' => (int) Config::get('floor_timeout_ms', 30000)],
            ];
        });

        if (random_int(1, 25) === 1) {
            $this->rooms->sweep();
        }
        return $result;
    }

    private function poll(array $c, int $since): array
    {
        return $this->rooms->transaction($c['ch'], function (array &$room, int $now, bool &$dirty) use ($c, $since): array {
            $pid = $c['pid'];
            if (!isset($room['peers'][$pid])) {
                throw new SignalException('not_joined', 'You are not in this channel any more.', 409);
            }
            $peer = &$room['peers'][$pid];
            if ($now - (int) $peer['last_seen'] > 3000) {
                $peer['last_seen'] = $now;
                $dirty = true;
            }
            if ($since > (int) ($peer['cursor'] ?? 0)) {
                $peer['cursor'] = min($since, (int) $room['seq']);
                $dirty = true;
            }
            unset($peer);

            $events = [];
            foreach ($room['events'] as $ev) {
                if ($ev['seq'] <= $since) {
                    continue;
                }
                $mine = $ev['to'] === $pid || ($ev['to'] === null && $ev['from'] !== $pid);
                if ($mine) {
                    $events[] = ['seq' => $ev['seq'], 'type' => $ev['type'], 'from' => $ev['from'], 'data' => $ev['data']];
                }
            }
            return ['ok' => true, 'seq' => $room['seq'], 'events' => $events, 'state' => $this->rooms->snapshot($room, $now)];
        });
    }

    private function pttRequest(array $c): array
    {
        return $this->rooms->transaction($c['ch'], function (array &$room, int $now, bool &$dirty) use ($c): array {
            $pid = $c['pid'];
            if (!isset($room['peers'][$pid])) {
                throw new SignalException('not_joined', 'You are not in this channel any more.', 409);
            }
            $sp = $room['speaker'];
            if ($sp && $sp['peer'] !== $pid) {
                return ['ok' => true, 'granted' => false, 'reason' => 'busy', 'speaker' => ['id' => $sp['peer'], 'nick' => $sp['nick']]];
            }
            if (!$sp) {
                $timeout = (int) Config::get('floor_timeout_ms', 30000);
                $room['speaker'] = ['peer' => $pid, 'nick' => $c['nick'], 'since' => $now, 'expires' => $now + $timeout];
                $this->rooms->emit($room, 'speaker_started', $pid, null, ['nick' => $c['nick']], $now);
                $dirty = true;
            }
            return [
                'ok'           => true,
                'granted'      => true,
                'timeout_ms'   => (int) Config::get('floor_timeout_ms', 30000),
                'remaining_ms' => max(0, $room['speaker']['expires'] - $now),
            ];
        });
    }

    private function pttRelease(array $c): array
    {
        return $this->rooms->transaction($c['ch'], function (array &$room, int $now, bool &$dirty) use ($c): array {
            if ($room['speaker'] && $room['speaker']['peer'] === $c['pid']) {
                $room['speaker'] = null;
                $this->rooms->emit($room, 'speaker_stopped', $c['pid'], null, ['nick' => $c['nick'], 'reason' => 'released'], $now);
                $dirty = true;
            }
            return ['ok' => true];
        });
    }

    /** Relay WebRTC offer / answer / ICE data to exactly one other user of the same channel. */
    private function signal(array $c, array $msg): array
    {
        $to = $msg['to'] ?? null;
        $data = $msg['data'] ?? null;
        if (!is_string($to) || !preg_match('/^[A-Za-z0-9_-]{6,40}$/', $to) || !is_array($data)) {
            throw new SignalException('bad_request', 'Invalid signaling message.', 400);
        }
        $encoded = (string) json_encode($data);
        if (strlen($encoded) > (int) Config::get('max_data', 12288)) {
            throw new SignalException('payload_too_large', 'Signaling data is too large.', 413);
        }
        $kind = $data['kind'] ?? '';
        $valid = match ($kind) {
            'offer', 'answer' => isset($data['sdp']) && is_string($data['sdp']),
            'ice'             => isset($data['candidate']) && is_array($data['candidate']),
            default           => false,
        };
        if (!$valid) {
            throw new SignalException('bad_request', 'Invalid signaling message.', 400);
        }

        return $this->rooms->transaction($c['ch'], function (array &$room, int $now, bool &$dirty) use ($c, $to, $data): array {
            if (!isset($room['peers'][$c['pid']])) {
                throw new SignalException('not_joined', 'You are not in this channel any more.', 409);
            }
            if ($to === $c['pid'] || !isset($room['peers'][$to])) {
                return ['ok' => true, 'delivered' => false];
            }
            $this->rooms->emit($room, 'signal', $c['pid'], $to, $data, $now);
            $dirty = true;
            return ['ok' => true, 'delivered' => true];
        });
    }

    private function leave(array $c): array
    {
        return $this->rooms->transaction($c['ch'], function (array &$room, int $now, bool &$dirty) use ($c): array {
            $pid = $c['pid'];
            if ($room['speaker'] && $room['speaker']['peer'] === $pid) {
                $room['speaker'] = null;
                $this->rooms->emit($room, 'speaker_stopped', $pid, null, ['nick' => $c['nick'], 'reason' => 'left'], $now);
                $dirty = true;
            }
            if (isset($room['peers'][$pid])) {
                unset($room['peers'][$pid]);
                $this->rooms->emit($room, 'peer_left', $pid, null, ['nick' => $c['nick'], 'reason' => 'left'], $now);
                $dirty = true;
            }
            return ['ok' => true];
        });
    }
}
