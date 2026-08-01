<?php

namespace App\Http\Controllers\Dashboard;

use App\Application\Settings\SettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function index()
    {
        return view('dashboard.settings', [
            'schema' => $this->settings->schema(),
            'values' => $this->settings->currentValues(),
        ]);
    }

    public function update(Request $request)
    {
        $this->settings->save($request->input('settings', []));

        return redirect()->route('settings')->with('status', 'Settings saved — they apply to the next research iteration.');
    }
}
