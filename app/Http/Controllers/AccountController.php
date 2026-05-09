<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\Reading;
use App\Services\Wallet\WalletService;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(private WalletService $wallet) {}

    public function dashboard(Request $request)
    {
        $user = $request->user();
        return view('pages.account.dashboard', [
            'user'    => $user,
            'recent'  => Reading::where('user_id', $user->id)->latest()->limit(10)->get(),
            'chats'   => ChatConversation::where('user_id', $user->id)
                ->withCount('messages')
                ->latest()
                ->limit(5)
                ->get(),
            'balance' => $this->wallet->balance($user),
        ]);
    }

    public function history(Request $request)
    {
        return view('pages.account.history', [
            'readings' => Reading::where('user_id', $request->user()->id)
                ->latest()
                ->paginate(20),
        ]);
    }

    public function chats(Request $request)
    {
        return view('pages.account.chats', [
            'conversations' => ChatConversation::where('user_id', $request->user()->id)
                ->withCount('messages')
                ->latest()
                ->paginate(20),
        ]);
    }
}
