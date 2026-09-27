<?php

namespace Gadya\Cms\Localisation;

use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Http\Middleware\LocaliseRequest;
use Gadya\Cms\Models\Translation;
use Gadya\Cms\Observers\InvalidatePublishedDocument;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the site's other languages need wired up, kept in one place
 * so the package's main provider only has to register this one.
 */
class LocalisationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Locales::class);
        $this->app->scoped(Translations::class);
        $this->app->singleton(TranslatableText::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/locales.php');

        /*
         * First of all the global middleware, so everything after it - the
         * redirects, the coming-soon notice, the router - sees the path
         * the application knows, without the language in front.
         */
        $this->app->make(Kernel::class)->prependMiddleware(LocaliseRequest::class);

        Translation::observe(InvalidatePublishedDocument::class);

        /*
         * Articles, events and terms are rows rather than parts of the
         * document, so they are put into the visitor's language as they
         * are read. Only ever while answering a request in another
         * language - never in the panel, a queue job or a command.
         */
        foreach (array_keys(Translations::MODELS) as $model) {
            $model::retrieved(function (Model $record): void {
                $locales = app(Locales::class);

                if (! $locales->isTranslating()) {
                    return;
                }

                app(Translations::class)->translateModel($record, $locales->current(), app(EditContext::class)->showsDraft());
            });
        }

        Blade::directive('cmsLanguageSwitcher', function (string $expression): string {
            $expression = trim($expression) === '' ? '[]' : $expression;

            return "<?php echo view('gadya-cms::components.language-switcher', (array) ({$expression}))->render(); ?>";
        });

        Blade::directive('cmsLang', fn (): string => "<?php echo e(str_replace('_', '-', app(\\Gadya\\Cms\\Localisation\\Locales::class)->current())); ?>");
    }
}
