<?php

namespace App\Http\Controllers;

use App\Models\Reading;
use App\Services\Numerology;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NumerologyController extends Controller
{
    public function index()
    {
        return view('pages.numerology.index');
    }

    public function calculate(Request $request, Numerology $numerology)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:128',
            'birth_date' => 'required|date',
        ]);

        $result = $numerology->analyze($data['name'], $data['birth_date']);

        $reading = Reading::create([
            'user_id' => $request->user()?->id,
            'session_token' => Str::uuid()->toString(),
            'type' => 'numerology',
            'question' => $data['name'],
            'payload' => $data,
            'result' => $result['narrative'],
        ]);

        return view('pages.numerology.result', [
            'reading' => $reading,
            'result' => $result,
        ]);
    }
}
