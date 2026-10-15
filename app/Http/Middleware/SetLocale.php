<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Logged-in user's locale; the guest's own choice (session); browser locale; otherwise the default (en). */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;

        if (! Locales::isSupported($locale)) {
            $locale = $request->session()->get(Locales::SESSION_KEY);
        }

        if (! Locales::isSupported($locale)) {
            $locale = Locales::fromAcceptLanguage($request->header('Accept-Language'));
        }

        // app()->setLocale() overwrites config('app.locale'), hence the default is a constant.
        app()->setLocale(Locales::isSupported($locale) ? $locale : Locales::DEFAULT);

        return $next($request);
    }
}
