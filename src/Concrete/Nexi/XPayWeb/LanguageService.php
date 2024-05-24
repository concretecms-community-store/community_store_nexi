<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Nexi\XPayWeb;

use Concrete\Core\Localization\Localization;
use MLocati\Nexi\XPayWeb\Dictionary\Language;

class LanguageService
{
    /**
     * @var \Concrete\Core\Localization\Localization
     */
    private $localization;

    /**
     * @var \MLocati\Nexi\XPayWeb\Dictionary\Language
     */
    private $language;

    public function __construct(Localization $localization, Language $language)
    {
        $this->localization = $localization;
        $this->language = $language;
    }

    /**
     * If the current locale is not valid or is not applicable to Nexi, returns $fallback.
     */
    public function getNexiCodeByCurrentLocale(string $fallback = Language::ID_ENG): string
    {
        return $this->getNexiCodeByLocale($this->localization->getLocale(), $fallback);
    }

    /**
     * If $locale is not valid or is not applicable to Nexi, returns $fallback.
     */
    public function getNexiCodeByLocale(string $localeID, string $fallback = Language::ID_ENG): string
    {
        return $this->language->getNexiCodeFromLocale($localeID) ?: $fallback;
    }
}
