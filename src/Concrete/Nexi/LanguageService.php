<?php
declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Nexi;

use Concrete\Core\Localization\Localization;
use MLocati\Nexi\Dictionary\Language;

class LanguageService
{
    /**
     * @var \Concrete\Core\Localization\Localization
     */
    private $localization;

    /**
     * @var \MLocati\Nexi\Dictionary\Language
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
        return $this->getNexiCodeByLocale($this->localization->getLocale());
    }

    /**
     * If $locale is not valid or is not applicable to Nexi, returns $fallback.
     */
    public function getNexiCodeByLocale(string $locale, string $fallback = Language::ID_ENG): string
    {
        [$isoCode] = explode('_', str_replace('-', '_', $locale));

        return $this->getNexiCodeByIsoCode($isoCode) ?: $fallback;
    }

    /**
     * If $isoCode is not valid or is not applicable to Nexi, returns $fallback.
     */
    public function getNexiCodeByIsoCode(string $isoCode, string $fallback = Language::ID_ENG): string
    {
        if ($isoCode !== '') {
            foreach ($this->language->getAvailableIDs() as $id) {
                if (strcasecmp($isoCode, $id) === 0) {
                    return $id;
                }
            }
            switch (strtolower($isoCode)) {
                case 'ja':
                    return Language::ID_JPN;
                case 'pt':
                    return Language::ID_POR;
                case 'es':
                    return Language::ID_SPA;
                case 'za':
                    return Language::ID_ZHA;
            }
            $result = '';
            foreach ($this->language->getAvailableIDs() as $id) {
                if (stripos($id, $isoCode) !== 0) {
                    continue;
                }
                if ($result !== '') {
                    $result = '';
                    break;
                }
                $result = $id;
            }
            if ($result !== '') {
                return $result;
            }
        }

        return $fallback;
    }
}
