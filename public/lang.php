<?php
/*
 * Language switcher: sets the language cookie and returns the visitor to the
 * page they came from.
 */
require __DIR__ . '/../app/bootstrap.php';

$lang = $_GET['lang'] ?? '';
if ($lang !== 'zh' && $lang !== 'en') {
    $lang = 'en';
}
set_lang($lang);

$return = $_GET['r'] ?? '/';
// only allow redirects within this host to avoid open redirects
$return = ltrim($return, '/');
if ($return !== '' && !str_starts_with($return, 'http')) {
    header('Location: ' . base_url() . '/' . $return);
} else {
    header('Location: ' . base_url() . '/');
}
exit;
