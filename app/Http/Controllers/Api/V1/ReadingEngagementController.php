<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Reading;
use App\Support\ReadingEngagement;
use App\Support\ReadingPresentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReadingEngagementController extends Controller
{
    public function share(Request $request, Reading $reading): JsonResponse
    {
        ReadingEngagement::ownFinished($request, $reading);
        $data = $request->validate(['public' => ['required', 'boolean']]);
        ReadingEngagement::share($reading, (bool) $data['public']);

        return response()->json(['data' => ReadingPresentation::details($reading)['share']]);
    }

    public function review(Request $request, Reading $reading): JsonResponse
    {
        $review = ReadingEngagement::review($request, $reading);

        return response()->json(['data' => ['review' => ReadingPresentation::review($review)]]);
    }
}
