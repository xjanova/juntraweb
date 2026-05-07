<?php

namespace App\Http\Controllers;

use App\Models\Reading;
use App\Services\AiOracle;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PalmistryController extends Controller
{
    public function index()
    {
        return view('pages.palmistry.index');
    }

    public function analyze(Request $request, AiOracle $oracle)
    {
        $request->validate([
            'image' => 'required|image|max:4096',
            'question' => 'nullable|string|max:500',
        ]);

        $path = $request->file('image')->store('palmistry', 'public');
        $absolute = storage_path('app/public/' . $path);

        $analysis = $oracle->analyzePalmImage($absolute, $request->input('question'));

        $reading = Reading::create([
            'user_id' => $request->user()?->id,
            'session_token' => Str::uuid()->toString(),
            'type' => 'palmistry',
            'question' => $request->input('question'),
            'payload' => ['image_path' => $path],
            'result' => $analysis,
            'ai_provider' => $oracle->provider(),
            'ai_model' => $oracle->model(),
        ]);

        return view('pages.palmistry.result', [
            'reading' => $reading,
            'image_url' => asset('storage/' . $path),
        ]);
    }
}
