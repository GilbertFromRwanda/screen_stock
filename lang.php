<?php
// Multi-language support (Kinyarwanda / English / French). Loaded by config.php
// on every request. The active language lives in $_SESSION['language'] — set
// at login from the user's saved users.language, or via switch_language.php
// (also usable pre-login, e.g. on login.php).
define('SUPPORTED_LANGUAGES', ['en' => 'English', 'rw' => 'Kinyarwanda', 'fr' => 'Français']);

function currentLang(): string {
    $lang = $_SESSION['language'] ?? 'en';
    return array_key_exists($lang, SUPPORTED_LANGUAGES) ? $lang : 'en';
}

function loadTranslations(string $lang): array {
    static $cache = [];
    if (isset($cache[$lang])) return $cache[$lang];
    $file = __DIR__ . "/lang/$lang.php";
    return $cache[$lang] = (array_key_exists($lang, SUPPORTED_LANGUAGES) && file_exists($file)) ? require $file : [];
}

// Translates $key into the current session language. Falls back to the
// English string, then to the raw key, so an untranslated string still
// renders as something readable instead of breaking the page.
function t(string $key, array $vars = []): string {
    $strings = loadTranslations(currentLang());
    $text    = $strings[$key] ?? (loadTranslations('en')[$key] ?? $key);
    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}
