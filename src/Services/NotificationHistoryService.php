<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Moztopia\Notifications\Models\NotificationDelivery;
use Moztopia\Notifications\Models\NotificationMessage;
use Moztopia\Notifications\Models\NotificationHistory;

final class NotificationHistoryService
{
    public function __construct(private readonly NotificationRecipientService $recipients)
    {
    }

    public function query(Model $recipient): Builder
    {
        $identity = $this->recipients->identity($recipient);

        return NotificationHistory::where('application', $identity['application'])
            ->where('notifiable_type', $identity['owner_type'])->where('notifiable_id', $identity['owner_id']);
    }

    public function paginate(Model $recipient, int $perPage = 20, bool $unreadOnly = false): LengthAwarePaginator
    {
        return $this->query($recipient)->when($unreadOnly, fn (Builder $query) => $query->unread())
            ->orderByDesc('id')->paginate(max(1, min(100, $perPage)));
    }

    public function unreadCount(Model $recipient): int
    {
        return $this->query($recipient)->unread()->count();
    }

    public function markAsRead(Model $recipient, string $notificationId): void
    {
        $this->query($recipient)->findOrFail($notificationId)->markAsRead();
    }

    public function markAsUnread(Model $recipient, string $notificationId): void
    {
        $this->query($recipient)->findOrFail($notificationId)->markAsUnread();
    }

    public function markAllAsRead(Model $recipient): void
    {
        $this->query($recipient)->unread()->update(['read_at' => now()]);
    }

    public function delete(Model $recipient, string $notificationId): void
    {
        $this->recipients->coordinate($recipient, function () use ($recipient, $notificationId): void {
            $this->query($recipient)->findOrFail($notificationId)->delete();
            NotificationMessage::where($this->recipients->identity($recipient))->whereKey($notificationId)
                ->update(['cancelled_at' => now(), 'payload' => null]);
            NotificationDelivery::where('message_id', $notificationId)->whereIn('state', ['pending', 'retryable_failure'])
                ->update(['state' => 'skipped', 'error_code' => 'notification_deleted']);
        });
    }
}
