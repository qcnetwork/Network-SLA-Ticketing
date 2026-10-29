<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * A notice shown under the bell in the navbar (database channel only).
 */
class InAppNotification extends Notification
{
    public function __construct(
        public string $title,
        public string $body,
        public string $url,
        public string $icon = 'inbox',
    ) {}

    /**
     * @return array<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'url' => $this->url, 'icon' => $this->icon];
    }
}
