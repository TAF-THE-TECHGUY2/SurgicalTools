<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

/** Admin-managed stock-count settings (spec §5): where the accounts reports go. */
class StockCountSettingsController extends Controller
{
    public function show(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['data' => $this->payload()]);
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'accounts_emails'   => ['present', 'array', 'max:10'],
            'accounts_emails.*' => ['required', 'email', 'max:255'],
        ]);

        Setting::put(Setting::ACCOUNTS_EMAIL, array_values(array_unique($data['accounts_emails'])));

        return response()->json(['data' => $this->payload()]);
    }

    /** @return array<string, mixed> */
    protected function payload(): array
    {
        return [
            'accounts_emails' => Setting::accountsEmails(),
            // What an empty list falls back to, so the screen can say so.
            'fallback_email'  => config('surgical.notifications.office'),
        ];
    }
}
