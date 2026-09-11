<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Store\Http\Resources\InventoryNotificationResource;
use App\Modules\Store\Services\InventoryAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request, InventoryAlertService $alerts): JsonResponse
    {
        $user = $request->user();
        $store = $user->store;

        if ($store !== null) {
            $alerts->scanStore($store);
        }

        $notifications = $user->notifications()->latest()->limit(40)->get();

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'data' => InventoryNotificationResource::collection($notifications)->resolve(),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['ok' => true]);
    }
}
