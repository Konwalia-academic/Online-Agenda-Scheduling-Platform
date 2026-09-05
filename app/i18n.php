<?php
/*
 * Internationalisation: loads the current language dictionary and exposes t().
 * Supported: zh (Simplified Chinese) and en (English).
 */
declare(strict_types=1);

function i18n_dictionaries(): array
{
    static $dicts = null;
    if ($dicts === null) {
        $en = require APP . '/../i18n/en.php';
        $zh = require APP . '/../i18n/zh.php';
        $dicts = ['en' => $en, 'zh' => $zh];
    }
    return $dicts;
}

function current_lang(): string
{
    $lang = $_COOKIE['lang'] ?? ($_SESSION['lang'] ?? '');
    if ($lang !== 'zh' && $lang !== 'en') {
        $lang = setting('default_lang', 'en');
        if ($lang !== 'zh' && $lang !== 'en') {
            $lang = 'en';
        }
    }
    return $lang;
}

function set_lang(string $lang): void
{
    if ($lang !== 'zh' && $lang !== 'en') {
        $lang = 'en';
    }
    $_SESSION['lang'] = $lang;
    if (PHP_SAPI !== 'cli') {
        setcookie('lang', $lang, [
            'expires' => time() + 60 * 60 * 24 * 365,
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
}

function t(string $key, mixed $replace = null): string
{
    $dicts = i18n_dictionaries();
    $lang = current_lang();
    $text = $dicts[$lang][$key] ?? $dicts['en'][$key] ?? $key;
    if ($replace !== null) {
        if (is_array($replace)) {
            foreach ($replace as $k => $v) {
                $text = str_replace('{' . $k . '}', (string)$v, $text);
            }
        } else {
            $text = str_replace('{v}', (string)$replace, $text);
        }
    }
    return $text;
}
