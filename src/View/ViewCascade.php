<?php

namespace Aerni\AdvancedSeo\View;

use Aerni\AdvancedSeo\Data\HasComputedData;
use Aerni\AdvancedSeo\Facades\SocialImage;
use Aerni\AdvancedSeo\Models\Defaults;
use Aerni\AdvancedSeo\Support\Helpers;
use Illuminate\Support\Collection;
use Spatie\SchemaOrg\Schema;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Blink;
use Statamic\Facades\Data;
use Statamic\Facades\Site;
use Statamic\Facades\URL;
use Statamic\Stache\Query\TermQueryBuilder;
use Statamic\Support\Str;
use Statamic\Tags\Context;

class ViewCascade extends BaseCascade
{
    use HasComputedData;

    public function __construct(Context $model)
    {
        parent::__construct($model);
    }

    protected function process(): self
    {
        return $this
            ->withSiteDefaults()
            ->withPageData()
            ->removeSeoPrefix()
            ->removeSectionFields()
            ->ensureOverrides()
            ->sortKeys();
    }

    public function computedKeys(): Collection
    {
        return collect([
            'site_name',
            'title',
            'og_image',
            'og_image_preset',
            'og_title',
            'twitter_card',
            'twitter_image',
            'twitter_image_preset',
            'twitter_title',
            'twitter_handle',
            'indexing',
            'locale',
            'hreflang',
            'canonical',
            'prev_url',
            'next_url',
            'site_schema',
            'page_schema',
            'breadcrumbs',
        ]);
    }

    protected function pageTitle(): ?string
    {
        // Handle taxonomy page.
        if ($this->model->get('terms') instanceof TermQueryBuilder) {
            return $this->model->get('title');
        }

        // Handle error page.
        if ($this->model->get('response_code') === 404) {
            return '404';
        }

        // Handle all other pages. Fall back to the model title if the SEO title is null.
        return $this->get('title') ?? $this->model->get('title');
    }

    public function siteName(): string
    {
        return $this->get('site_name') ?? Site::current()->name();
    }

    public function title(): string
    {
        $siteNamePosition = $this->get('site_name_position');
        $titleSeparator = $this->get('title_separator');
        $siteName = $this->siteName();
        $pageTitle = $this->pageTitle();

        return match (true) {
            (! $pageTitle) => $siteName,
            ($siteNamePosition == 'end') => "{$pageTitle} {$titleSeparator} {$siteName}",
            ($siteNamePosition == 'start') => "{$siteName} {$titleSeparator} {$pageTitle}",
            ($siteNamePosition == 'disabled') => $pageTitle,
            default => "{$pageTitle} {$titleSeparator} {$siteName}",
        };
    }

    public function ogTitle(): string
    {
        return $this->get('og_title') ?? $this->pageTitle() ?? $this->siteName();
    }

    public function ogImage(): ?Asset
    {
        return $this->get('generate_social_images')
            ? $this->get('generated_og_image') ?? $this->get('og_image')
            : $this->get('og_image');
    }

    public function ogImagePreset(): array
    {
        return collect(SocialImage::findModel('open_graph'))
            ->only(['width', 'height'])
            ->all();
    }

    public function twitterTitle(): string
    {
        return $this->get('twitter_title') ?? $this->pageTitle() ?? $this->siteName();
    }

    public function twitterCard(): string
    {
        return match (true) {
            ($this->has('twitter_card')) => $this->get('twitter_card'),
            // The following three cases handle pages like taxonomy and 404.
            ($this->has('twitter_summary_large_image')) => SocialImage::findModel('twitter_summary_large_image')['card'],
            ($this->has('twitter_summary_image')) => SocialImage::findModel('twitter_summary')['card'],
            default => Defaults::data('collections')->get('seo_twitter_card'),
        };
    }

    public function twitterImage(): ?Asset
    {
        if (! $model = SocialImage::findModel("twitter_{$this->twitterCard()}")) {
            return null;
        }

        return $this->get('generate_social_images')
            ? $this->get('generated_twitter_image') ?? $this->get($model['handle'])
            : $this->get($model['handle']);
    }

    public function twitterImagePreset(): array
    {
        return collect(SocialImage::findModel("twitter_{$this->twitterCard()}"))
            ->only(['width', 'height'])
            ->all();
    }

    public function twitterHandle(): ?string
    {
        $twitterHandle = $this->get('twitter_handle');

        return $twitterHandle ? Str::start($twitterHandle, '@') : null;
    }

    public function indexing(): ?string
    {
        $indexing = collect([
            'noindex' => $this->get('noindex'),
            'nofollow' => $this->get('nofollow'),
        ])->filter()->keys()->implode(', ');

        return ! empty($indexing) ? $indexing : null;
    }

    public function locale(): string
    {
        return Helpers::parseLocale(Site::current()->locale());
    }

    public function hreflang(): ?array
    {
        // Handles collection taxonomy index page.
        if ($this->model->has('segment_2') && $this->model->get('terms') instanceof TermQueryBuilder) {
            $taxonomy = $this->model->get('title')->augmentable();

            return $taxonomy->sites()->map(function ($site) use ($taxonomy) {
                $site = Site::get($site);
                $siteUrl = $site->absoluteUrl();
                $taxonomyHandle = $taxonomy->handle();
                $collectionHandle = $taxonomy->collection()->handle();

                return [
                    'url' => URL::tidy("{$siteUrl}/{$collectionHandle}/{$taxonomyHandle}"),
                    'locale' => Helpers::parseLocale($site->locale()),
                ];
            })->all();
        }

        // Handles collection taxonomy show page.
        if ($this->model->has('segment_3') && $this->model->value('is_term') === true) {
            $localizedTerm = $this->model->get('title')->augmentable();

            return $localizedTerm->taxonomy()->sites()
                ->map(fn ($locale) => [
                    'url' => $localizedTerm->in($locale)->absoluteUrl(),
                    'locale' => Helpers::parseLocale(Site::get($locale)->locale()),
                ])->all();
        }

        // Handles taxonomy index page.
        if ($this->model->has('segment_1') && $this->model->get('terms') instanceof TermQueryBuilder) {
            $taxonomy = $this->model->get('terms')->first()->taxonomy();

            $initialSite = Site::current()->handle();

            $data = $taxonomy->sites()->map(function ($locale) use ($taxonomy) {
                // Set the current site so we can get the localized absolute URLs of the taxonomy.
                Site::setCurrent($locale);

                return [
                    'url' => $taxonomy->absoluteUrl(),
                    'locale' => Helpers::parseLocale(Site::get($locale)->locale()),
                ];
            })->toArray();

            // Reset the site to the original.
            Site::setCurrent($initialSite);

            return $data;
        }

        // Handle entries and term show page.
        $data = Data::find($this->model->get('id'));

        if (! $data) {
            return null;
        }

        $sites = $data instanceof Entry
            ? $data->sites()
            : $data->taxonomy()->sites();

        $hreflang = $sites->map(fn ($locale) => $data->in($locale))
            ->filter() // A model might no exist in a site. So we need to remove it to prevent calling methods on null
            ->filter(fn ($model) => $model->published()) // Remove any unpublished entries/terms
            ->filter(fn ($model) => $model->url()) // Remove any entries/terms with no route
            ->map(fn ($model) => [
                'url' => $model->absoluteUrl(),
                'locale' => Helpers::parseLocale($model->site()->locale()),
            ])->all();

        return $hreflang;
    }

    public function canonical(): ?string
    {
        $type = $this->get('canonical_type');

        if ($type == 'other' && $this->has('canonical_entry')) {
            return $this->get('canonical_entry')->absoluteUrl();
        }

        if ($type == 'custom' && $this->has('canonical_custom')) {
            return $this->get('canonical_custom');
        }

        $currentUrl = $this->model->get('current_url');

        // Don't add the pagination parameter if it doesn't exists or there's no paginator on the page.
        if (! app('request')->has('page') || ! Blink::get('tag-paginator')) {
            return $currentUrl;
        }

        $page = (int) app('request')->get('page');

        // Don't include the pagination parameter for the first page. We don't want the same site to be indexed with and without parameter.
        return $page === 1
            ? $currentUrl
            : "{$currentUrl}?page={$page}";
    }

    public function prevUrl(): ?string
    {
        if (! $paginator = Blink::get('tag-paginator')) {
            return null;
        }

        $currentUrl = $this->model->get('current_url');

        $page = $paginator->currentPage();

        // Don't include the pagination parameter for the first page. We don't want the same site to be indexed with and without parameter.
        if ($page === 2) {
            return $currentUrl;
        }

        return $page > 1 && $page <= $paginator->lastPage()
            ? $currentUrl.'?page='.($page - 1)
            : null;
    }

    public function nextUrl(): ?string
    {
        if (! $paginator = Blink::get('tag-paginator')) {
            return null;
        }

        $currentUrl = $this->model->get('current_url');

        $page = $paginator->currentPage();

        return $page < $paginator->lastPage()
            ? $currentUrl.'?page='.($page + 1)
            : null;
    }

    public function siteSchema(): ?string
    {
        $type = $this->get('site_json_ld_type');

        if (! $type || $type == 'none') {
            return null;
        }

        if ($type == 'custom') {
            return $this->get('site_json_ld');
        }

        $siteUrl = $this->model->get('site')?->absoluteUrl() ?? Site::current()->absoluteUrl();

        if ($type == 'organization') {
            $schema = Schema::organization()
                ->name($this->get('organization_name'))
                ->url($siteUrl);

            if ($logo = $this->get('organization_logo')) {
                $logo = Schema::imageObject()
                    ->url($logo->absoluteUrl())
                    ->width($logo->width())
                    ->height($logo->height());

                $schema->logo($logo);
            }
        }

        if ($type == 'person') {
            $schema = Schema::person()
                ->name($this->get('person_name'))
                ->url($siteUrl);
        }

        return json_encode($schema->toArray(), JSON_UNESCAPED_UNICODE);
    }

    public function pageSchema(): ?string
    {
        return $this->get('json_ld')?->value();
    }

    public function breadcrumbs(): ?string
    {
        // Don't render breadcrumbs if deactivated in the site defaults.
        if (! $this->get('use_breadcrumbs')) {
            return null;
        }

        // Don't render breadcrumbs on the homepage.
        if ($this->model->get('is_homepage')) {
            return null;
        }

        $listItems = $this->breadcrumbsListItems()->map(function ($crumb) {
            return Schema::listItem()
                ->position($crumb['position'])
                ->name($crumb['title'])
                ->item($crumb['url']);
        })->all();

        $breadcrumbs = Schema::breadcrumbList()->itemListElement($listItems);

        return json_encode($breadcrumbs->toArray(), JSON_UNESCAPED_UNICODE);
    }

    protected function breadcrumbsListItems(): Collection
    {
        $segments = collect(request()->segments())->prepend('/');

        $crumbs = $segments->map(function () use (&$segments) {
            $uri = URL::tidy($segments->join('/'));
            $segments->pop();

            return Data::findByUri(Str::ensureLeft($uri, '/'), Site::current()->handle());
        })
            ->filter()
            ->reverse()
            ->values()
            ->map(function ($item, $key) {
                return [
                    'position' => $key + 1,
                    'title' => method_exists($item, 'title') ? $item->title() : $item->value('title'),
                    'url' => $item->absoluteUrl(),
                ];
            });

        return $crumbs;
    }
}
