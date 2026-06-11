<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionUsageLog;
use Illuminate\Http\Request;

class SubscriptionUsageLogController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => SubscriptionUsageLog::latest()
                ->paginate(20)
        ]);
    }

    public function show(SubscriptionUsageLog $subscriptionUsageLog)
    {
        return response()->json([
            'success' => true,
            'data' => $subscriptionUsageLog
        ]);
    }
}