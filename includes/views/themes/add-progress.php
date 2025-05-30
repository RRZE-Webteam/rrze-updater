<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

$upgrader = $data['upgrader'];
$repoZip = $data['repoZip'];

$upgrader->install($repoZip);
