<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserSubscription\UserSubscriptionStoreRequest;
use App\Http\Requests\UserSubscription\UserSubscriptionUpdateRequest;
use App\Http\Resources\UserSubscription\UserSubscriptionResource;
use App\Models\SubscriptionUsageLog;
use App\Models\UserSubscription;
use Illuminate\Http\Request;

class UserSubscriptionController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => UserSubscriptionResource::collection(
                UserSubscription::with(['user', 'package'])
                    ->latest()
                    ->paginate(10)
            )
        ]);



        
    }

    public function store(UserSubscriptionStoreRequest $request)
    {
        $subscription = UserSubscription::create(
            $request->validated()
        );
    
        SubscriptionUsageLog::create([
            'subscription_id' => $subscription->userSubscriptionsID,
            'user_id' => $subscription->user_id,
            'type' => 'subscription',
            'action' => 'created',
            'value' => 1,
            'description' => 'Subscription created',
            'metadata' => [
                'package_id' => $subscription->package_id,
                'subscription_key' => $subscription->subscription_key,
                'status' => $subscription->status,
            ],
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Subscription created successfully',
            'data' => new UserSubscriptionResource(
                $subscription->load(['user', 'package'])
            )
        ], 201);
    }
    public function show(UserSubscription $subscription)
    {
        return response()->json([
            'success' => true,
            'data' => new UserSubscriptionResource(
                $subscription->load(['user', 'package'])
            )
        ]);
    }
    public function update(
        UserSubscriptionUpdateRequest $request,
        UserSubscription $subscription
    ) {
        $oldStatus = $subscription->status;
    
        $subscription->update(
            $request->validated()
        );
    
        SubscriptionUsageLog::create([
            'subscription_id' => $subscription->userSubscriptionsID,
            'user_id' => $subscription->user_id,
            'type' => 'subscription',
            'action' => 'updated',
            'value' => 1,
            'description' => "Subscription updated from {$oldStatus} to {$subscription->status}",
        ]);
    
        return response()->json([
            'success' => true,
            'message' => 'Subscription updated successfully',
            'data' => new UserSubscriptionResource(
                $subscription->fresh()->load(['user', 'package'])
            )
        ]);
    }
    public function destroy(UserSubscription $subscription)
    {
        SubscriptionUsageLog::create([
            'subscription_id' => $subscription->userSubscriptionsID,
            'user_id' => $subscription->user_id,
            'type' => 'subscription',
            'action' => 'deleted',
            'value' => 1,
            'description' => 'Subscription deleted',
        ]);
    
        $subscription->delete();
    
        return response()->json([
            'success' => true,
            'message' => 'Subscription deleted successfully'
        ]);
    }
}