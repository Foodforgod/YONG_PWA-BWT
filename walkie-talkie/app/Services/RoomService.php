<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Channel;

/**
 * Room storage. Every channel is ONE JSON file in storage/rooms/.
 *
 * Room layout:
 *   name, seq, created, updated,
 *   peers   : { peerId: {nick, joined, last_seen, cursor} }
 *   speaker : null | {peer, nick, since, expires}    <- the "floor"
 *   events  : [ {seq, type, from, to, data, ts} ]    <- messages waiting for pollers
 *
 * All changes happen inside transaction(), which holds an exclusive file lock
 * (flock). That is what prevents two people from grabbing the floor at once.
 */
final class RoomService
{
    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function path(string $channel): string
    {
        $dir = rtrim((string) Config::get('storage_path'), '/\\') . '/rooms';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/' . Channel::fileName($channel) . '.json';
    }

    /**
     * Run $fn(array &$room, int $now, bool &$dirty) while the room file is locked.
     * Stale users and expired talk time are cleaned up first.
     */
    public function transaction(string $channel, callable $fn): mixed
    {
        $fh = @fopen($this->path($channel), 'c+');
        if (!$fh) {
            throw new \RuntimeException('Cannot open the room file. Check permissions of storage/rooms.');
        }
        flock($fh, LOCK_EX);
        try {
            $room = json_decode((string) stream_get_contents($fh), true);
            if (!is_array($room) || !isset($room['peers'], $room['events'])) {
                $room = $this->blank($channel);
            }
            $now = self::nowMs();
            $dirty = $this->maintain($room, $now);
            try {
                $result = $fn($room, $now, $dirty);
            } catch (\Throwable $e) {
                $this->persist($fh, $room, $now, $dirty);
                throw $e;
            }
            $this->persist($fh, $room, $now, $dirty);
            return $result;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** Number of users currently in the channel (0 when the room does not exist). */
    public function count(string $channel): int
    {
        if (!is_file($this->path($channel))) {
            return 0;
        }
        return (int) $this->transaction($channel, static fn (array &$room): int => count($room['peers']));
    }

    public function isFull(string $channel): bool
    {
        return $this->count($channel) >= (int) Config::get('max_peers', 8);
    }

    /** Append an event for the pollers. $to = null means "everybody". */
    public function emit(array &$room, string $type, ?string $from, ?string $to, array $data, int $now): void
    {
        $room['seq']++;
        $room['events'][] = ['seq' => $room['seq'], 'type' => $type, 'from' => $from, 'to' => $to, 'data' => $data, 'ts' => $now];
    }

    /** Public state that is sent to every client. */
    public function snapshot(array $room, int $now): array
    {
        $speaker = $room['speaker'];
        $peers = [];
        foreach ($room['peers'] as $id => $p) {
            $peers[] = ['id' => $id, 'nick' => $p['nick'], 'speaking' => $speaker && $speaker['peer'] === $id];
        }
        return [
            'peers'     => $peers,
            'speaker'   => $speaker ? ['id' => $speaker['peer'], 'nick' => $speaker['nick'], 'remaining_ms' => max(0, $speaker['expires'] - $now)] : null,
            'seq'       => $room['seq'],
            'max_peers' => (int) Config::get('max_peers', 8),
        ];
    }

    /** Remove rooms that are empty and have not been used for an hour. */
    public function sweep(): void
    {
        $dir = rtrim((string) Config::get('storage_path'), '/\\') . '/rooms';
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (filemtime($file) > time() - 3600) {
                continue;
            }
            $fh = @fopen($file, 'c+');
            if (!$fh) {
                continue;
            }
            flock($fh, LOCK_EX);
            $room = json_decode((string) stream_get_contents($fh), true);
            $empty = !is_array($room) || empty($room['peers']);
            flock($fh, LOCK_UN);
            fclose($fh);
            if ($empty) {
                @unlink($file);
            }
        }
    }

    private function blank(string $channel): array
    {
        $now = self::nowMs();
        return ['name' => $channel, 'seq' => 0, 'created' => $now, 'updated' => $now, 'peers' => [], 'speaker' => null, 'events' => []];
    }

    private function persist($fh, array &$room, int $now, bool $dirty): void
    {
        if (!$dirty) {
            return;
        }
        $room['updated'] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode($room, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fh);
    }

    /** Housekeeping: drop silent users, end expired talk time, prune delivered events. */
    private function maintain(array &$room, int $now): bool
    {
        $dirty = false;
        $timeout = (int) Config::get('peer_timeout_ms', 15000);

        foreach ($room['peers'] as $id => $p) {
            if ($now - (int) $p['last_seen'] > $timeout) {
                unset($room['peers'][$id]);
                $this->emit($room, 'peer_left', $id, null, ['nick' => $p['nick'], 'reason' => 'timeout'], $now);
                if ($room['speaker'] && $room['speaker']['peer'] === $id) {
                    $room['speaker'] = null;
                    $this->emit($room, 'speaker_stopped', $id, null, ['nick' => $p['nick'], 'reason' => 'left'], $now);
                }
                $dirty = true;
            }
        }

        $sp = $room['speaker'];
        if ($sp && $now >= (int) $sp['expires']) {
            $room['speaker'] = null;
            $this->emit($room, 'speaker_stopped', $sp['peer'], null, ['nick' => $sp['nick'], 'reason' => 'timeout'], $now);
            $this->emit($room, 'ptt_timeout', $sp['peer'], $sp['peer'], [], $now);
            $dirty = true;
        }

        $before = count($room['events']);
        $room['events'] = array_values(array_filter($room['events'], static function (array $ev) use ($room, $now): bool {
            if ($now - (int) $ev['ts'] > 20000) {
                return false;
            }
            if ($ev['to'] !== null) {
                $p = $room['peers'][$ev['to']] ?? null;
                return $p !== null && $ev['seq'] > (int) ($p['cursor'] ?? 0);
            }
            foreach ($room['peers'] as $id => $p) {
                if ($id !== $ev['from'] && (int) ($p['cursor'] ?? 0) < $ev['seq']) {
                    return true;
                }
            }
            return false;
        }));
        return $dirty || count($room['events']) !== $before;
    }
}
