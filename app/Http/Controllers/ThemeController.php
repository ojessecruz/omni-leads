<?php

namespace App\Http\Controllers;

use App\Enums\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Theme switcher in the app header (same preference as Settings › Appearance). */
class ThemeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $theme = $request->validate([
            'theme' => ['required', Rule::enum(Theme::class)],
        ])['theme'];

        $request->user()->update(['theme' => $theme]);

        return back();
    }
}
