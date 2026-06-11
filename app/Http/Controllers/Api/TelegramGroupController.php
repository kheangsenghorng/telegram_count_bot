<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramGroup;
use Illuminate\Http\Request;

class TelegramGroupController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => TelegramGroup::with(['user', 'subscription.package'])
                ->latest()
                ->paginate(10)
        ]);
    }

    public function show(TelegramGroup $telegramGroup)
    {
        return response()->json([
            'success' => true,
            'data' => $telegramGroup->load(['user', 'subscription.package'])
        ]);
    }

    public function destroy(TelegramGroup $telegramGroup)
    {
        $telegramGroup->update([
            'status' => 'disconnected',
        ]);

        $telegramGroup->delete();

        return response()->json([
            'success' => true,
            'message' => 'Telegram group disconnected successfully'
        ]);
    }
}