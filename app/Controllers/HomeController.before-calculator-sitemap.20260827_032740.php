<?php

declare(strict_types=1);

namespace Khatauat\Controllers;

use Khatauat\Core\Database;
use Khatauat\Core\Settings;
use Khatauat\Core\View;

final class HomeController
{
    public function index(): void
    {
        $services = Database::fetchAll("SELECT s.*, c.name AS category_name FROM services s LEFT JOIN categories c ON c.id=s.category_id WHERE s.status='published' ORDER BY s.published_at DESC, s.id DESC LIMIT 6");
        $updates = Database::fetchAll("SELECT * FROM updates WHERE status='published' ORDER BY COALESCE(published_at,created_at) DESC LIMIT 4");
        $articles = Database::fetchAll("SELECT * FROM articles WHERE status='published' ORDER BY published_at DESC LIMIT 3");
        $calculators = Database::fetchAll("SELECT * FROM calculators WHERE status='published' ORDER BY updated_at DESC, id DESC LIMIT 3");
        $categories = Database::fetchAll("SELECT c.*, COUNT(s.id) AS services_count FROM categories c LEFT JOIN services s ON s.category_id=c.id AND s.status='published' GROUP BY c.id ORDER BY services_count DESC,c.name LIMIT 12");
        $featuredJourney = Database::fetch("SELECT s.*, c.name AS category_name FROM services s LEFT JOIN categories c ON c.id=s.category_id WHERE s.status='published' ORDER BY (SELECT COUNT(*) FROM service_steps st WHERE st.service_id=s.id) DESC,s.id DESC LIMIT 1");
        $journeySteps = $featuredJourney ? Database::fetchAll('SELECT * FROM service_steps WHERE service_id=? ORDER BY position LIMIT 5',[(int)$featuredJourney['id']]) : [];
        $registryCount = 0;
        try { $registryCount = (int)(Database::fetch('SELECT COUNT(*) c FROM source_registry WHERE status="active"')['c'] ?? 0); } catch (\Throwable) {}
        $stats = [
            'services' => (int)(Database::fetch("SELECT COUNT(*) c FROM services WHERE status='published'")['c'] ?? 0),
            'sources' => max((int)(Database::fetch("SELECT COUNT(*) c FROM sources")['c'] ?? 0), $registryCount),
            'verified' => (int)(Database::fetch("SELECT COUNT(*) c FROM service_steps st JOIN services s ON s.id=st.service_id WHERE s.status='published' AND st.trust_status='verified' AND st.source_id IS NOT NULL AND st.verified_at IS NOT NULL")['c'] ?? 0),
            'registry' => $registryCount,
        ];
        View::render('home', compact('services','updates','articles','calculators','stats','categories','featuredJourney','journeySteps') + ['title' => 'خطوات — ابدأ من هدفك واعرف طريقك']);
    }

    public function search(): void
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $results = [];
        if (mb_strlen($q) >= 2) {
            $like = '%' . $q . '%';
            $results = Database::fetchAll("SELECT s.*, c.name AS category_name FROM services s LEFT JOIN categories c ON c.id=s.category_id WHERE s.status='published' AND (s.name LIKE ? OR s.summary LIKE ? OR s.official_entity LIKE ? OR s.requirements LIKE ?) ORDER BY s.name LIMIT 30", [$like,$like,$like,$like]);
        }
        View::render('search', ['title' => 'نتائج البحث', 'q' => $q, 'results' => $results]);
    }

    public function contact(): void
    {
        if (\is_post()) {
            \Khatauat\Core\Csrf::verify();
            $name = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($message) < 10) {
                \flash('error', 'تحقق من الاسم والبريد ونص الرسالة.');
            } else {
                Database::execute('INSERT INTO contact_messages(name,email,message,status,created_at) VALUES(?,?,?,?,CURRENT_TIMESTAMP)', [$name,$email,$message,'new']);
                \flash('success', 'تم استلام رسالتك.');
                \redirect('contact');
            }
        }
        View::render('contact', ['title' => 'تواصل معنا']);
    }

    public function about(): void { View::render('about', ['title' => 'من نحن']); }
    public function privacy(): void { View::render('privacy', ['title' => 'الخصوصية والإعلانات']); }
    public function terms(): void { View::render('terms', ['title' => 'الشروط والأحكام']); }

    public function adEvent(): void
    {
        $payload = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($payload['experiment_id'] ?? 0);
        $variant = strtoupper((string)($payload['variant'] ?? ''));
        if ($id < 1 || !in_array($variant, ['A','B'], true)) { \json_response(['ok'=>false], 422); }
        $exp = Database::fetch("SELECT id,status FROM ad_experiments WHERE id=? AND status='running'", [$id]);
        $sessionVariant = $_SESSION['_ad_variant_' . $id] ?? null;
        if (!$exp || $sessionVariant !== $variant) { \json_response(['ok'=>false], 403); }
        Database::execute('INSERT INTO ad_experiment_events(experiment_id,variant,event_type,created_at) VALUES(?,?,?,CURRENT_TIMESTAMP)', [$id,$variant,'click']);
        \json_response(['ok'=>true]);
    }

    public function analyticsEvent(): void
    {
        if ((string)Settings::get('internal_analytics_enabled', '1') !== '1') {
            \json_response(['ok' => true, 'disabled' => true]);
        }
        $payload = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
        $path = trim((string)($payload['path'] ?? '/'));
        if ($path === '' || !str_starts_with($path, '/')) $path = '/';
        $path = mb_substr((string)(parse_url($path, PHP_URL_PATH) ?: '/'), 0, 255);
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/analytics/')) {
            \json_response(['ok' => true, 'ignored' => true]);
        }

        $referrer = trim((string)($payload['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? '')));
        $refHost = strtolower((string)(parse_url($referrer, PHP_URL_HOST) ?: ''));
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($refHost === $host) $refHost = '';
        $utm = strtolower(trim((string)($payload['utm_source'] ?? '')));
        $source = 'direct';
        if ($utm !== '') $source = mb_substr($utm, 0, 50);
        elseif ($refHost !== '') {
            if (str_contains($refHost, 'google.')) $source = 'google';
            elseif (str_contains($refHost, 'bing.')) $source = 'bing';
            elseif (preg_match('/(facebook|instagram|tiktok|x\.com|twitter|snapchat|linkedin)/', $refHost)) $source = 'social';
            else $source = 'referral';
        }

        $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $device = preg_match('/ipad|tablet|kindle|silk/', $ua) ? 'tablet' : (preg_match('/mobile|iphone|android/', $ua) ? 'mobile' : 'desktop');
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $sid = session_id() ?: bin2hex(random_bytes(8));
        $sessionHash = hash('sha256', $sid . '|' . date('Y-m-d'));

        try {
            Database::execute('INSERT INTO traffic_events(event_type,page_path,referrer_host,source_category,device_type,session_hash,created_at) VALUES(?,?,?,?,?,?,CURRENT_TIMESTAMP)', ['pageview',$path,mb_substr($refHost,0,120),mb_substr($source,0,50),$device,$sessionHash]);
        } catch (\Throwable) {
            // Analytics must never break the public page.
        }
        \json_response(['ok' => true]);
    }

    public function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        $base = rtrim((string)\config('base_url', ''), '/');
        if ($base === '') $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "Sitemap: {$base}/sitemap.xml\n";
    }

    public function sitemap(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        $base = rtrim((string)\config('base_url', ''), '/');
        if ($base === '') $base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $today = date('Y-m-d');

        /*
         * Public canonical pages that should be discoverable.
         * Private/account/payment routes must never be included.
         */
        $staticPaths = [
            '/',
            '/procedures',
            '/calculators',
            '/updates',
            '/about',
            '/contact',
            '/plans',
        ];

        $urls = [];

        foreach ($staticPaths as $path) {
            $urls[] = [
                'loc' => $base . $path,
                'lastmod' => $today,
            ];
        }
        foreach (Database::fetchAll("SELECT slug, updated_at FROM services WHERE status='published' AND indexable=1") as $s) $urls[]=['loc'=>$base.'/service/'.rawurlencode($s['slug']),'lastmod'=>substr((string)$s['updated_at'],0,10)];
        foreach (Database::fetchAll("SELECT slug, updated_at FROM articles WHERE status='published'") as $a) $urls[]=['loc'=>$base.'/article/'.rawurlencode($a['slug']),'lastmod'=>substr((string)$a['updated_at'],0,10)];
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($urls as $u) echo '<url><loc>'.htmlspecialchars($u['loc'],ENT_XML1).'</loc><lastmod>'.htmlspecialchars($u['lastmod'],ENT_XML1)."</lastmod></url>\n";
        echo '</urlset>';
    }
}
