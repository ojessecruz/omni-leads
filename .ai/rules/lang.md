---
paths:
  - 'lang/**'
---

# Lang

## i18n: English keys via __(), translations in pt_BR/es JSON
User-facing text is written in English as the __() key; translations live in lang/pt_BR.json and lang/es.json (LocaleTest fails on any missing key). Exceptions, log messages, CLI output and every code comment (PHP docblocks, inline, Blade, CSS, tests) are written in English and are not translated. Validation messages go in lang/{locale}/validation.php. Never use config('app.locale') as a default; the SetLocale middleware calls app()->setLocale().
