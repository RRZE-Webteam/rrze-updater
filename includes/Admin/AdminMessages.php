<?php

namespace RRZE\Updater\Admin;

defined('ABSPATH') || exit;

/** Collects notices shared by administrative actions within one request. */
class AdminMessages
{
    /** @var array Request notices in the order they were produced. */
    private array $messages = [];

    /**
     * Append a notice without rendering it or changing repository state.
     *
     * @param mixed $message Success text or a WordPress error.
     * @return void
     */
    public function add($message): void
    {
        $this->messages[] = $message;
    }

    /**
     * Read every notice accumulated during this request.
     *
     * @return array Request notices in insertion order.
     */
    public function all(): array
    {
        return $this->messages;
    }

    /**
     * Read the most recently appended notice.
     *
     * @return mixed Last notice, or false when the collection is empty.
     */
    public function last()
    {
        return $this->messages === [] ? false : $this->messages[array_key_last($this->messages)];
    }
}
