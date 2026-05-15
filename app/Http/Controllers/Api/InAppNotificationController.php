<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\InAppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InAppNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min(max((int) $request->get('per_page', 15), 1), 50);
        $query = InAppNotification::query()->where('user_id', $user->id)->orderByDesc('id');

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        $paginator = $query->paginate($perPage);

        return ApiResponse::success([
            'items' => collect($paginator->items())->map(fn (InAppNotification $n) => $this->row($n))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'unread_count' => InAppNotification::query()
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    /** Recent items for dropdown (no pagination totals needed). */
    public function dropdown(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = InAppNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(12)
            ->get()
            ->map(fn (InAppNotification $n) => $this->row($n))
            ->all();

        $unread = InAppNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return ApiResponse::success(['items' => $items, 'unread_count' => $unread]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'type' => ['nullable', 'string', 'max:80'],
            'data' => ['nullable', 'array'],
        ]);

        $n = InAppNotification::create([
            'user_id' => $data['user_id'],
            'title' => $data['title'],
            'body' => $data['body'],
            'type' => $data['type'] ?? null,
            'data' => $data['data'] ?? null,
        ]);

        return ApiResponse::success(['notification' => $this->row($n)], 'Created', 201);
    }

    public function markRead(Request $request, InAppNotification $in_app_notification): JsonResponse
    {
        if ($in_app_notification->user_id !== $request->user()->id) {
            return ApiResponse::error('Forbidden.', 403);
        }

        if ($in_app_notification->read_at === null) {
            $in_app_notification->update(['read_at' => now()]);
        }

        return ApiResponse::success(['notification' => $this->row($in_app_notification->fresh())]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        InAppNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return ApiResponse::success(null, 'Marked read');
    }

    /** @return array<string, mixed> */
    private function row(InAppNotification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'data' => $n->data,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at->toIso8601String(),
        ];
    }
}
