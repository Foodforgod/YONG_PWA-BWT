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
use App\Services\RateLimiter;
use App\Services\RoomService;

final class HomeController
{
    /** GET /  -  landing / join page */
    public function index(Request $request): void
    {
        $data = [
            'pageTitle' => 'Join a channel',
            'error'     => Session::flash('error'),
            'nickname'  => (string) (Session::flash('old_nickname') ?? Session::get('nickname', '')),
            'channel'   => (string) (Session::flash('old_channel') ?? ''),
            'csrf'      => Session::csrfToken(),
            'publicUrl' => public_app_url(),
            'maxPeers'  => (int) Config::get('max_peers', 8),
            'scripts'   => [
                '<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>',
                '<script type="module" src="' . e(asset('js/join.js')) . '"></script>',
            ],
        ];
        Session::close();
        View::render('join', $data);
    }

    /** POST /join  -  validate input, store guest identity in the session */
    public function join(Request $request): void
    {
        if (!RateLimiter::allow('join:' . $request->ip(), 40, 60)) {
            Response::error(429, 'Too many attempts', 'Please wait a minute and try again.');
        }

        [$nickname, $nickError] = Guest::validateNickname((string) $request->input('nickname', ''));
        [$channel, $chError] = Channel::validate((string) $request->input('channel', ''));
        $error = $nickError ?? $chError;

        if ($error === null) {
            $max = (int) Config::get('max_peers', 8);
            if ((new RoomService())->isFull($channel)) {
                $error = "This channel is currently full. Maximum $max users are allowed.";
            }
        }

        if ($error !== null) {
            Session::flash('error', $error);
            Session::flash('old_nickname', $nickname);
            Session::flash('old_channel', (string) $request->input('channel', ''));
            Response::redirect('/');
        }

        Session::regenerate();
        if (!Session::get('guest_id')) {
            Session::set('guest_id', Guest::newGuestId());
        }
        Session::set('nickname', $nickname);
        Session::set('channel', $channel);
        Response::redirect('/channel');
    }
}
