<?php

namespace App\Http\Controllers;

use App\Models\Reading;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function dashboard(Request $request)
    {
        return view('pages.account.dashboard', [
            'user' => $request->user(),
            'recent' => Reading::where('user_id', $request->user()->id)->latest()->limit(10)->get(),
        ]);
    }

    public function history(Request $request)
    {
        return view('pages.account.history', [
            'readings' => Reading::where('user_id', $request->user()->id)->latest()->paginate(20),
        ]);
    }
}
