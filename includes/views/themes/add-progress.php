<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

$upgrader = $data['upgrader'];
$zipUrl = $data['zipUrl'];

$upgrader->install($zipUrl);
