<?php

declare(strict_types=1);

namespace Khatauat\Controllers;

use Khatauat\Core\Database;
use Khatauat\Core\View;
use Khatauat\Services\Seo;
use Khatauat\Services\CalculatorService;

final class ContentController
{
    public function blog(): void
    {
        $articles = Database::fetchAll("SELECT * FROM articles WHERE status='published' ORDER BY published_at DESC");
        View::render('content/blog', ['title' => 'المدونة', 'articles' => $articles]);
    }

    public function article(string $slug): void
    {
        $a = Database::fetch("SELECT * FROM articles WHERE slug=? AND status='published'", [$slug]);
        if (!$a) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'المقال غير موجود']);
            return;
        }
        $jsonld = Seo::articleJsonLd($a);

        /* KHATAUAT_ARTICLE_BREADCRUMB_V1 */
        $breadcrumbs = Seo::breadcrumbJsonLd([
            [
                'name' => 'الرئيسية',
                'url' => \url(''),
            ],
            [
                'name' => 'المدونة',
                'url' => \url('blog'),
            ],
            [
                'name' => (string)$a['title'],
                'url' => \url('article/' . $a['slug']),
            ],
        ]);

        View::render('content/article', [
            'title' => $a['seo_title'] ?: $a['title'],
            'metaDescription' => $a['seo_description'] ?: $a['summary'],
            'ogType' => 'article',
            'article' => $a,
            'articleJsonLd' => $jsonld,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    public function calculators(): void
    {
        $items = CalculatorService::published();

        $breadcrumbs = Seo::breadcrumbJsonLd([
            [
                'name' => 'الرئيسية',
                'url' => \url(''),
            ],
            [
                'name' => 'الحاسبات',
                'url' => \url('calculators'),
            ],
        ]);

        View::render('content/calculators', [
            'title' => 'حاسبات السعودية: الضريبة والزكاة والرواتب والتمويل | خطوات',
            'metaDescription' => 'مجموعة حاسبات وأدوات رقمية للضريبة والزكاة ومكافأة نهاية الخدمة والرواتب والتمويل والنسب والتواريخ وتكاليف الأعمال في السعودية.',
            'calculators' => $items,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    public function calculator(string $slug): void
    {
        $calculator = CalculatorService::find($slug);
        if (!$calculator) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'الحاسبة غير موجودة']);
            return;
        }
        $all = CalculatorService::published();

        $breadcrumbs = Seo::breadcrumbJsonLd([
            [
                'name' => 'الرئيسية',
                'url' => \url(''),
            ],
            [
                'name' => 'الحاسبات',
                'url' => \url('calculators'),
            ],
            [
                'name' => (string)$calculator['name'],
                'url' => \url(
                    'calculator/' . $calculator['slug']
                ),
            ],
        ]);

        View::render('content/calculator', [
            'title' => CalculatorService::seoTitle($calculator),
            'metaDescription' => CalculatorService::seoDescription($calculator),
            'calculator' => $calculator,
            'calculators' => $all,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    public function updates(): void
    {
        $items = Database::fetchAll("SELECT u.*, s.title source_title,s.url source_url FROM updates u LEFT JOIN sources s ON s.id=u.source_id WHERE u.status='published' ORDER BY COALESCE(u.published_at,u.created_at) DESC");
        View::render('content/updates', ['title' => 'تحديثات الجهات', 'updates' => $items]);
    }
}
