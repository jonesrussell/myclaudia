<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\I18n\Language;
use Waaseyaa\I18n\LanguageManager;
use Waaseyaa\I18n\LanguageManagerInterface;

final class I18nServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(LanguageManagerInterface::class, function (): LanguageManagerInterface {
            return new LanguageManager([
                new Language('en', 'English', isDefault: true),
            ]);
        });
    }
}
