<?php

namespace App\Http\Controllers;

use App\Actions\Calendar\CalendarFeeds;
use Illuminate\Http\Response;

/**
 * Serves the private ICS feeds. The token in the URL is the only
 * credential, so an unknown token is simply not found.
 */
class CalendarFeedController extends Controller
{
    public function personal(CalendarFeeds $feeds, string $token): Response
    {
        $user = $feeds->userFor($token) ?? abort(404);

        return $this->ics($feeds->personal($user), 'hive.ics');
    }

    public function event(CalendarFeeds $feeds, string $token, string $code): Response
    {
        $user = $feeds->userFor($token) ?? abort(404);

        return $this->ics($feeds->event($user, $code) ?? abort(404), strtolower($code).'.ics');
    }

    private function ics(string $body, string $filename): Response
    {
        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'Cache-Control' => 'private, max-age=900',
        ]);
    }
}
