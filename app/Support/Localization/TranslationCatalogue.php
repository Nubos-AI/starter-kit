<?php

declare(strict_types=1);

namespace App\Support\Localization;

use Illuminate\Translation\Translator;

class TranslationCatalogue
{
    public function __construct(private readonly Translator $translator) {}

    /** @return array{locale: string, fallbackLocale: string, messages: array<string, mixed>} */
    public function forLocale(string $locale): array
    {
        $fallback = $this->translator->getFallback();
        $groups = ['i18n'];

        foreach (array_keys($this->translator->getLoader()->namespaces()) as $namespace) {
            $groups[] = $namespace.'::i18n';
        }

        $messages = [];

        foreach ($groups as $group) {
            $default = $this->translator->get($group, [], $fallback);
            $translated = $this->translator->get($group, [], $locale);

            if (is_array($default) || is_array($translated)) {
                $messages[$group] = array_replace_recursive(
                    is_array($default) ? $default : [],
                    is_array($translated) ? $translated : [],
                );
            }
        }

        return ['locale' => $locale, 'fallbackLocale' => $fallback, 'messages' => $messages];
    }
}
