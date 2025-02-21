<?php

namespace Aerni\AdvancedSeo\View;

use Aerni\AdvancedSeo\Data\HasComputedData;
use Aerni\AdvancedSeo\Facades\SocialImage;
use Aerni\AdvancedSeo\Support\Helpers;
use Illuminate\Support\Collection;
use Spatie\SchemaOrg\Schema;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\Facades\Data;
use Statamic\Facades\URL;
use Statamic\Support\Str;

class GraphQlCascade extends BaseCascade
{
    use HasComputedData;

    protected ?string $baseUrl = null;

    public function __construct(Entry|Term $model)
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
            'twitter_image',
            'twitter_image_preset',
            'twitter_title',
            'twitter_handle',
            'indexing',
            'locale',
            'hreflang',
            'canonical',
            'site_schema',
            'breadcrumbs',
        ]);
    }

    protected function pageTitle(): string
    {
        return $this->get('title') ?? $this->model->get('title');
    }

    public function siteName(): string
    {
        return $this->get('site_name') ?? $this->model->site()->name();
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

    public function twitterImage(): ?Asset
    {
        if (! $model = SocialImage::findModel("twitter_{$this->get('twitter_card')}")) {
            return null;
        }

        return $this->get('generate_social_images')
            ? $this->get('generated_twitter_image') ?? $this->get($model['handle'])
            : $this->get($model['handle']);
    }

    public function twitterImagePreset(): array
    {
        return collect(SocialImage::findModel("twitter_{$this->get('twitter_card')}"))
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
        return Helpers::parseLocale($this->model->site()->locale());
    }

    public function hreflang(): array
    {
        $sites = $this->model instanceof Entry
            ? $this->model->sites()
            : $this->model->taxonomy()->sites();

        $root = $this->model instanceof Entry
            ? $this->model->root()
            : $this->model->inDefaultLocale();

        $hreflang = $sites->map(fn ($locale) => $this->model->in($locale))
            ->filter() // A model might no exist in a site. So we need to remove it to prevent calling methods on null
            ->filter(fn ($model) => $model->published()) // Remove any unpublished entries/terms
            ->filter(fn ($model) => $model->url()) // Remove any entries/terms with no route
            ->map(fn ($model) => [
                'url' => $this->buildUrl($model),
                'locale' => Helpers::parseLocale($model->site()->locale()),
            ])->push([
                'url' => $this->buildUrl($root),
                'locale' => 'x-default',
            ])->all();

        return $hreflang;
    }

    public function canonical(): ?string
    {
        $type = $this->get('canonical_type');

        if ($type == 'other' && $this->has('canonical_entry')) {
            return $this->buildUrl($this->get('canonical_entry'));
        }

        if ($type == 'custom' && $this->has('canonical_custom')) {
            return $this->get('canonical_custom');
        }

        return $this->buildUrl($this->model);
    }

    public function siteSchema(): ?string
    {
        $type = $this->get('site_json_ld_type');

        if ($type == 'none') {
            return null;
        }

        if ($type == 'custom') {
            return $this->get('site_json_ld');
        }

        if ($type == 'organization') {
            $schema = Schema::organization()
                ->name($this->get('organization_name'))
                ->url($this->buildUrl($this->model->site()));

            if ($logo = $this->get('organization_logo')) {
                $logo = Schema::imageObject()
                    ->url($this->buildUrl($logo))
                    ->width($logo->width())
                    ->height($logo->height());

                $schema->logo($logo);
            }
        }

        if ($type == 'person') {
            $schema = Schema::person()
                ->name($this->get('person_name'))
                ->url($this->buildUrl($this->model->site()));
        }

        return json_encode($schema->toArray(), JSON_UNESCAPED_UNICODE);
    }

    public function breadcrumbs(): ?string
    {
        // Don't render breadcrumbs if deactivated in the site defaults.
        if (! $this->get('use_breadcrumbs')) {
            return null;
        }

        // Don't render breadcrumbs on the homepage.
        if ($this->model->absoluteUrl() === $this->model->site()->absoluteUrl()) {
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
        $segments = collect(explode('/', $this->model->url()))->filter()->prepend('/');

        $crumbs = $segments->map(function () use (&$segments) {
            $uri = URL::tidy($segments->join('/'));
            $segments->pop();

            return Data::findByUri(Str::ensureLeft($uri, '/'), $this->model->site()->handle());
        })
            ->filter()
            ->reverse()
            ->values()
            ->map(fn ($model, $key) => [
                'position' => $key + 1,
                'title' => method_exists($model, 'title') ? $model->title() : $model->value('title'),
                'url' => $this->buildUrl($model),
            ]);

        return $crumbs;
    }

    public function baseUrl(?string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    protected function buildUrl(mixed $model): ?string
    {
        return $this->baseUrl
            ? URL::assemble($this->baseUrl, $model->url())
            : $model->absoluteUrl();
    }
}
