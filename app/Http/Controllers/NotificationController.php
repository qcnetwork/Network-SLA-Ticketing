<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

class NotificationController extends Controller
{
    /**
     * How many the bell lists; the rest are on the history page.
     */
    public const BELL_LIMIT = 25;

    /**
     * The bell's contents. Polled by every open page, so it stays a couple of cheap queries.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()->latest()->limit(self::BELL_LIMIT)->get()->map(fn (DatabaseNotification $notification) => [
                'id' => $notification->id,
                'title' => $notification->data['title'] ?? '',
                'body' => $notification->data['body'] ?? '',
                'icon' => $notification->data['icon'] ?? 'inbox',
                'url' => route('notifications.open', $notification->id),
                'time' => $notification->created_at->diffForHumans(),
                'read' => $notification->read_at !== null,
            ]),
        ]);
    }

    /**
     * The signed-in user's own notification log, newest first.
     */
    public function history(Request $request): View
    {
        $filter = $request->query('filter') === 'unread' ? 'unread' : 'all';
        $user = $request->user();

        return view('notifications.history', [
            'filter' => $filter,
            'unread' => $user->unreadNotifications()->count(),
            'total' => $user->notifications()->count(),
            'notifications' => ($filter === 'unread' ? $user->unreadNotifications() : $user->notifications())
                ->latest()->paginate(25)->withQueryString(),
        ]);
    }

    /**
     * Mark one notification read and go to what it is about.
     */
    public function open(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return redirect()->to($notification->data['url'] ?? route('dashboard'));
    }

    /**
     * JSON for the bell; a redirect back for the button on the log page.
     */
    public function readAll(Request $request): JsonResponse|RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $request->expectsJson()
            ? response()->json(['unread' => 0])
            : back()->with('success', 'Semua notifikasi ditandai sudah dibaca');
    }
}
