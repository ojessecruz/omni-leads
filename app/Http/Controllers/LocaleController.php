<?php

namespace App\Http\Controllers;

use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Language switcher available on the landing page and inside the app. */
class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = $request->validate([
            'locale' => ['required', Rule::in(array_keys(Locales::SUPPORTED))],
        ])['locale'];

        // Signed-in users keep the choice on the account; guests only for the session.
        if ($user = $request->user()) {
            $user->update(['locale' => $locale]);
            $request->session()->forget(Locales::SESSION_KEY);
        } else {
            $request->session()->put(Locales::SESSION_KEY, $locale);
        }

        return back();
    }
}
