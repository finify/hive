<?php

namespace App\Http\Controllers;

use App\Actions\Demo\ResetDemo;
use App\Actions\Demo\SwitchPersona;
use App\Demo\DemoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class DemoController extends Controller
{
    public function switch(Request $request, SwitchPersona $switch, DemoData $data): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = $switch->handle((string) $request->input('email'));
        $persona = collect($data->raw('personas'))->firstWhere('email', $user->email);
        $target = $persona && Route::has($persona['start']) ? route($persona['start']) : route('dashboard');

        return redirect($target)->with('toast', ['message' => "Now signed in as {$user->name}."]);
    }

    public function reset(Request $request, ResetDemo $reset): RedirectResponse
    {
        $reset->handle($request->user());

        return back()->with('toast', ['message' => 'Demo reset. Everything is back to how it started.']);
    }
}
