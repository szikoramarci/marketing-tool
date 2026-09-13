<?php

namespace App\QuizConfig;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VisitorToken
{
    private const string SESSION_KEY = 'quiz_visitor_token';

    /**
     * A per-browser-session (not persistent-cookie) visitor identifier, per spec section 6:
     * "A látogatóazonosító session-token, nem tartós cookie."
     */
    public static function resolve(Request $request): string
    {
        if (! $request->session()->has(self::SESSION_KEY)) {
            $request->session()->put(self::SESSION_KEY, (string) Str::ulid());
        }

        return $request->session()->get(self::SESSION_KEY);
    }
}
