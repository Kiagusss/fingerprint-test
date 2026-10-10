<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Simple UI language switch (id | en). Purely cosmetic — it does not affect any
 * security decision.
 */
class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, ['id', 'en'], true), 404);

        $request->session()->put('locale', $locale);

        return redirect()->back();
    }
}
