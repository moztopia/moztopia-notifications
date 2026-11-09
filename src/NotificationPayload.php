<?php

declare(strict_types=1);

namespace Moztopia\Notifications;

use InvalidArgumentException;

final readonly class NotificationPayload
{
    public string $category;

    public string $priority;

    public function __construct(
        public string $title,
        public string $body,
        ?string $category = null,
        public ?string $imageUrl = null,
        public ?string $deepLink = null,
        public array $data = [],
        ?string $priority = null,
        public ?string $collapseId = null,
        public ?int $ttl = null,
    ) {
        $this->category = $category ?? config('moztopia-notifications.defaults.category', 'general');
        $this->priority = $priority ?? config('moztopia-notifications.defaults.priority', 'normal');
        $category = $this->category;
        $priority = $this->priority;
        if ($category === '' || strlen($category) > 120 || !in_array($priority, ['normal', 'high'], true)
            || ($collapseId !== null && strlen($collapseId) > 64) || ($ttl !== null && ($ttl < 1 || $ttl > 2419200))
            || strlen(json_encode($this->toArray(), JSON_THROW_ON_ERROR)) > 2800) {
            throw new InvalidArgumentException('Invalid notification options or payload exceeds the 2800-byte portable budget.');
        }
        if ($imageUrl !== null && (!filter_var($imageUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($imageUrl, PHP_URL_SCHEME), ['https', 'http'], true))) {
            throw new InvalidArgumentException('Notification images require an HTTP or HTTPS URL.');
        }
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
