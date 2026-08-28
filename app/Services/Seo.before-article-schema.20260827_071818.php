<?php

declare(strict_types=1);

namespace Khatauat\Services;

final class Seo
{
    public static function breadcrumbJsonLd(array $items): string
    {
        $list = [];
        foreach (array_values($items) as $i => $item) {
            $list[] = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ];
        }
        return json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $list,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public static function articleJsonLd(array $article): string
    {
        $sourceUrls = ArticleDraftMapper::normalizeUrls((string) ($article['source_urls'] ?? ''));
        $json = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $article['title'],
            'description' => $article['summary'],
            'datePublished' => $article['published_at'],
            'dateModified' => $article['updated_at'] ?: $article['published_at'],
            'mainEntityOfPage' => \url('article/' . $article['slug']),
            'inLanguage' => 'ar-SA',
            'author' => ['@type' => 'Organization', 'name' => \config('name', 'خطوات')],
            'publisher' => ['@type' => 'Organization', 'name' => \config('name', 'خطوات')],
        ];
        if ($sourceUrls !== []) $json['citation'] = $sourceUrls;
        return json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
