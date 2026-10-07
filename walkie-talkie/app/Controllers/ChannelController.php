<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Channel;
use App\Models\Guest;
use App\Services\TokenService;

final class ChannelController
{
    /** GET /channel  -  the walkie-talkie screen */
    public function show(Request $request): void
    {
        $nickname = (string) Session::get('nickname', '');
        $channel = (string) Session::get('channel', '');
        $guestId = (string) Session::get('guest_id', '');

        if ($nickname === '' || !Channel::isValid($channel) || $guestId === '') {
            Session::flash('error', 'Please enter a nickname and a channel first.');
            Response::redirect('/');
        }
        // The exact user limit is enforced by the signaling "hello" call (room_full), and the join form
        // already warns early, so a page reload while the room is full still works.

        $peerId = Guest::newPeerId($guestId);
        $config = [
            'peerId'         => $peerId,
            'nickname'       => $nickname,
            'channel'        => $channel,
            'token'          => TokenService::issue($peerId, $nickname, $channel),
            'signalUrl'      => url('signal'),
            'iceServers'     => Config::get('ice_servers'),
            'floorTimeoutMs' => (int) Config::get('floor_timeout_ms', 30000),
            'maxPeers'       => (int) Config::get('max_peers', 8),
        ];

        $data = [
            'pageTitle' => '#' . $channel,
            'nickname'  => $nickname,
            'channel'   => $channel,
            'config'    => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'bodyClass' => 'page-channel',
            'scripts'   => ['<script type="module" src="' . e(asset('js/app.js')) . '"></script>'],
        ];
        Session::close();
        View::render('channel', $data);
    }

    /** POST /leave  -  forget the channel and return to the join page */
    public function leave(Request $request): void
    {
        Session::forget('channel');
        Response::redirect('/');
    }
}
