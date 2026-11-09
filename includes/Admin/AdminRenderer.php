<?php

namespace RRZE\Updater\Admin;

defined('ABSPATH') || exit;

use function RRZE\Updater\plugin;

/** Renders administrative templates with explicit data and notices. */
class AdminRenderer
{
    /**
     * Render a complete administrative page.
     *
     * @param string $view Template name relative to the view directory.
     * @param array $data Explicit template data.
     * @param array $messages Notices for this request.
     * @return void
     */
    public function display(string $view, array $data = [], array $messages = []): void
    {
        $this->begin($messages);
        $data['messages'] = $messages;
        $this->partial($view, $data);
        $this->end();
    }

    /**
     * Verify the existing page permissions and render the wrapper and notices.
     *
     * @param array $messages Notices for this request.
     * @return void
     */
    public function begin(array $messages = []): void
    {
        if (!current_user_can('update_plugins') || !current_user_can('update_themes')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }
        $this->partial('partials/page-start', ['messages' => $messages]);
    }

    /**
     * Include one existing template without applying administrative actions.
     *
     * @param string $view Template name relative to the view directory.
     * @param array $data Explicit template data.
     * @return void
     */
    public function partial(string $view, array $data = []): void
    {
        $file = plugin()->getPath('includes') . 'views/' . $view . '.php';
        if ($view !== '' && is_file($file)) {
            include $file;
        }
    }

    /**
     * Close the administrative page wrapper.
     *
     * @return void
     */
    public function end(): void
    {
        $this->partial('partials/page-end');
    }
}
