<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

class Logger
{
    /**
     * Sends an informative message when informational logging is enabled.
     *
     * @param Settings $settings Plugin settings.
     * @param string   $message  Message for the log channel.
     * @param array    $context  Context for the log message.
     * @return void
     */
    public static function info(Settings $settings, string $message, array $context = []): void
    {
        if (!$settings->isInfoLoggingEnabled()) {
            return;
        }

        do_action('rrze.log.info', $message, $context);
    }
}
