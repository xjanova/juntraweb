<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\AiOracle;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $token = $request->session()->get('chat_token');
        if (!$token) {
            $token = Str::uuid()->toString();
            $request->session()->put('chat_token', $token);
        }

        $conversation = ChatConversation::firstOrCreate(
            ['session_token' => $token, 'user_id' => $request->user()?->id],
            ['title' => 'สนทนากับแม่หมอ']
        );

        return view('pages.chat.index', [
            'conversation' => $conversation->load('messages'),
        ]);
    }

    public function send(Request $request, AiOracle $oracle)
    {
        $data = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $token = $request->session()->get('chat_token');
        abort_unless($token, 403);

        $conversation = ChatConversation::where('session_token', $token)->firstOrFail();

        ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $data['message'],
        ]);

        $history = $conversation->messages()->latest()->limit(20)->get()->reverse()->values();
        $reply = $oracle->chat($history->all());

        ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['reply' => $reply]);
        }

        return redirect()->route('chat.index')->with('status', 'แม่หมอตอบกลับแล้ว');
    }

    public function show(ChatConversation $conversation)
    {
        return view('pages.chat.index', [
            'conversation' => $conversation->load('messages'),
        ]);
    }
}
