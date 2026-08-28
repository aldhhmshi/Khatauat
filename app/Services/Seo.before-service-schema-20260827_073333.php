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
        $root = rtrim(
            (string)\url(''),
            '/'
        );

        $siteName = trim(
            (string)\Khatauat\Core\Settings::get(
                'site_name',
                'خطوات'
            )
        );

        if ($siteName === '') {
            $siteName = 'خطوات';
        }

        $absoluteUrl = static function(
            string $value
        ): string {

            $value = trim($value);

            if ($value === '') {
                return '';
            }

            if (
                preg_match(
                    '~^https?://~i',
                    $value
                )
            ) {
                return $value;
            }

            return \url(
                ltrim($value, '/')
            );
        };

        $isoDate = static function(
            ?string $value
        ): ?string {

            $value = trim(
                (string)$value
            );

            if ($value === '') {
                return null;
            }

            try {
                return (
                    new \DateTimeImmutable($value)
                )->format(DATE_ATOM);

            } catch (\Throwable $e) {

                return null;
            }
        };

        $slug = trim(
            (string)($article['slug'] ?? '')
        );

        $articleUrl =
            \url(
                'article/'
                . rawurlencode($slug)
            );

        $organizationId =
            $root . '/#organization';

        $publisher = [
            '@type' => 'Organization',
            '@id' => $organizationId,
            'name' => $siteName,
            'url' => $root . '/',
        ];

        $logoPath = trim(
            (string)\Khatauat\Core\Settings::get(
                'site_logo_path',
                ''
            )
        );

        if ($logoPath !== '') {

            $logoUrl =
                $absoluteUrl($logoPath);

            if ($logoUrl !== '') {

                $publisher['logo'] = [
                    '@type' => 'ImageObject',
                    'url' => $logoUrl,
                    'contentUrl' => $logoUrl,
                ];
            }
        }

        $description = trim(
            (string)(
                $article['seo_description']
                ?? ''
            )
        );

        if ($description === '') {

            $description = trim(
                (string)(
                    $article['summary']
                    ?? ''
                )
            );
        }

        $json = [
            '@context' =>
                'https://schema.org',

            '@type' =>
                'Article',

            '@id' =>
                $articleUrl . '#article',

            'headline' =>
                (string)(
                    $article['title']
                    ?? ''
                ),

            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $articleUrl,
            ],

            'inLanguage' =>
                'ar-SA',

            'author' => [
                '@type' =>
                    'Organization',
                '@id' =>
                    $organizationId,
                'name' =>
                    $siteName,
                'url' =>
                    $root . '/',
            ],

            'publisher' =>
                $publisher,

            'isAccessibleForFree' =>
                true,
        ];

        if ($description !== '') {
            $json['description'] =
                $description;
        }

        $published = $isoDate(
            (string)(
                $article['published_at']
                ?? ''
            )
        );

        if ($published !== null) {
            $json['datePublished'] =
                $published;
        }

        $modified = $isoDate(
            (string)(
                $article['updated_at']
                ?? $article['published_at']
                ?? ''
            )
        );

        if ($modified !== null) {
            $json['dateModified'] =
                $modified;
        }

        /*
         * Do not invent an article image.
         * Only include a real featured image.
         */
        $featuredImage = trim(
            (string)(
                $article['featured_image']
                ?? ''
            )
        );

        if ($featuredImage !== '') {

            $imageUrl =
                $absoluteUrl(
                    $featuredImage
                );

            if ($imageUrl !== '') {

                $json['image'] = [
                    '@type' =>
                        'ImageObject',
                    'url' =>
                        $imageUrl,
                    'contentUrl' =>
                        $imageUrl,
                ];
            }
        }

        /*
         * Preserve article citations when available.
         */
        if (
            class_exists(
                ArticleDraftMapper::class
            )
        ) {
            $sourceUrls =
                ArticleDraftMapper::normalizeUrls(
                    (string)(
                        $article['source_urls']
                        ?? ''
                    )
                );

            if ($sourceUrls !== []) {
                $json['citation'] =
                    $sourceUrls;
            }
        }

        return json_encode(
            $json,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );
    }
}
