<?php

declare(strict_types=1);

namespace App\Core\Localization;

use App\Core\Security\Csrf;
use DOMDocument;
use DOMElement;

/** Applies the shared AdminLTE shell without changing controller workflows. */
final class AdminLayout
{
    private const ADMINLTE = '4.9.1';
    private const BOOTSTRAP_ICONS = '1.11.3';
    private const BOOTSTRAP = '5.3.8';

    public function __construct(private readonly string $basePath) {}

    public function apply(DOMDocument $document, string $path, array $context): void
    {
        $head = $document->getElementsByTagName('head')->item(0);
        $body = $document->getElementsByTagName('body')->item(0);
        if (!$head instanceof DOMElement || !$body instanceof DOMElement) return;
        $root = $document->getElementsByTagName('html')->item(0);
        if ($root instanceof DOMElement) $root->setAttribute('data-bs-theme', 'light');

        $this->dependency($document, $head, 'link', [
            'rel'=>'stylesheet', 'href'=>'https://cdn.jsdelivr.net/npm/bootstrap-icons@'.self::BOOTSTRAP_ICONS.'/font/bootstrap-icons.min.css',
            'crossorigin'=>'anonymous',
        ]);
        $this->dependency($document, $head, 'link', [
            'rel'=>'stylesheet', 'href'=>'https://cdn.jsdelivr.net/npm/admin-lte@'.self::ADMINLTE.'/dist/css/adminlte.min.css',
            'crossorigin'=>'anonymous',
        ]);
        $this->dependency($document, $body, 'script', [
            'src'=>'https://cdn.jsdelivr.net/npm/bootstrap@'.self::BOOTSTRAP.'/dist/js/bootstrap.bundle.min.js', 'crossorigin'=>'anonymous', 'defer'=>'defer',
        ]);
        $this->dependency($document, $body, 'script', [
            'src'=>'https://cdn.jsdelivr.net/npm/admin-lte@'.self::ADMINLTE.'/dist/js/adminlte.min.js', 'crossorigin'=>'anonymous', 'defer'=>'defer',
        ]);

        $standalone = in_array($path, ['/login','/install','/update'], true) || !($context['authenticated'] ?? false);
        if ($standalone) {
            $body->setAttribute('class', 'login-page bg-body-secondary seo-standalone');
            foreach (iterator_to_array($body->childNodes) as $child) {
                if ($child instanceof DOMElement && $child->tagName === 'script') continue;
                if ($child instanceof DOMElement) $child->setAttribute('class', trim($child->getAttribute('class').' card shadow-lg border-0 seo-auth-card'));
            }
            return;
        }

        $body->setAttribute('class', 'layout-fixed sidebar-expand-lg bg-body-tertiary');
        $body->setAttribute('data-bs-theme', 'light');
        $main = $document->getElementsByTagName('main')->item(0);
        if (!$main instanceof DOMElement) return;
        $main->setAttribute('class', trim($main->getAttribute('class').' app-main card border-0 shadow-sm p-4'));

        $wrapper = $document->createElement('div'); $wrapper->setAttribute('class', 'app-wrapper');
        $body->insertBefore($wrapper, $body->firstChild);
        $baseUrl = htmlspecialchars((string)($context['base_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $wrapper->appendChild($this->fragment($document, $this->navbar($context, $baseUrl)));
        $wrapper->appendChild($this->fragment($document, $this->sidebar($path, (array)($context['permissions'] ?? []), (array)($context['modules'] ?? []), $baseUrl)));
        $main->parentNode?->removeChild($main); $wrapper->appendChild($main);
        $footer = $document->createElement('footer'); $footer->setAttribute('class', 'app-footer');
        $footer->appendChild($this->fragment($document, $this->footer((string)($context['version'] ?? ''))));
        $wrapper->appendChild($footer);

        $title = $document->getElementsByTagName('h1')->item(0);
        if ($title instanceof DOMElement) {
            $header = $document->createElement('div'); $header->setAttribute('class', 'app-content-header mb-4 border-bottom pb-3');
            $title->parentNode?->insertBefore($header, $title); $header->appendChild($title);
        }
        $this->components($document);
    }

    private function navbar(array $context, string $baseUrl): string
    {
        $name = htmlspecialchars((string)($context['user']['name'] ?? 'کاربر'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $token = htmlspecialchars(Csrf::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<nav class="app-header navbar navbar-expand bg-body shadow-sm"><div class="container-fluid"><button class="btn btn-link" type="button" data-lte-toggle="sidebar" aria-label="باز و بسته کردن منو"><i class="bi bi-list" aria-hidden="true"></i></button><span class="navbar-brand fw-bold">SEO Tracker</span><div class="me-auto d-flex align-items-center gap-3"><a class="nav-link" href="'.$baseUrl.'/account"><i class="bi bi-person-circle" aria-hidden="true"></i> '.$name.'</a><form method="post" action="'.$baseUrl.'/logout"><input type="hidden" name="_token" value="'.$token.'"><button class="btn btn-outline-secondary btn-sm" type="submit">خروج</button></form></div></div></nav>';
    }

    private function sidebar(string $path, array $permissions, array $modules, string $baseUrl): string
    {
        $items = [
            ['/account','bi-speedometer2','داشبورد',null,null],
            ['/websites','bi-globe2','وب‌سایت‌ها','websites.view','websites'],
            ['/keywords','bi-key','کلیدواژه‌ها','keywords.view','keywords'],
            ['/rank-dashboard','bi-graph-up-arrow','ردیابی رتبه','rank_tracking.view','rank_tracking'],
            ['/reports','bi-file-earmark-bar-graph','گزارش‌ها','reports.view','reports'],
            ['/websites/search-console/dashboard','bi-google','سرچ کنسول گوگل','search_console.sync','search_console'],
            ['/admin/users','bi-people','کاربران','users.view',null],
            ['/admin/roles','bi-shield-lock','نقش‌ها و مجوزها','roles.manage',null],
            ['/settings','bi-person-gear','تنظیمات کاربری',null,'settings'],
            ['/admin/settings','bi-gear','تنظیمات و ماژول‌ها','settings.manage','settings'],
        ];
        $links = '';
        foreach ($items as [$href,$icon,$label,$permission,$module]) {
            if ($permission !== null && !in_array($permission, $permissions, true)) continue;
            if ($module !== null && !in_array($module, $modules, true)) continue;
            $active = ($path === $href || ($href !== '/account' && str_starts_with($path, $href.'/'))) ? ' active' : '';
            $links .= '<li class="nav-item"><a class="nav-link'.$active.'" href="'.$baseUrl.$href.'"><i class="nav-icon bi '.$icon.'" aria-hidden="true"></i><p>'.$label.'</p></a></li>';
        }
        return '<aside class="app-sidebar bg-dark shadow" data-bs-theme="dark"><div class="sidebar-brand"><a class="brand-link" href="'.$baseUrl.'/account"><span class="brand-text fw-semibold">SEO Tracker</span></a></div><div class="sidebar-wrapper"><nav class="mt-2" aria-label="منوی اصلی"><ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="menu">'.$links.'</ul></nav></div></aside>';
    }

    private function footer(string $version): string
    {
        $logs = $this->recentLogs();
        return '<div class="footer-content"><div class="footer-inner"><span>SEO Tracker · نسخه '.htmlspecialchars($version, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span><button class="btn btn-outline-primary btn-sm logbox-button" type="button" data-bs-toggle="modal" data-bs-target="#seo-logbox"><i class="bi bi-terminal" aria-hidden="true"></i> لاگ باکس</button></div>'
            .'<div class="modal fade" id="seo-logbox" tabindex="-1" aria-labelledby="seo-logbox-title" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="seo-logbox-title">لاگ باکس</h2><button type="button" class="btn-close m-0" data-bs-dismiss="modal" aria-label="بستن"></button></div><div class="modal-body"><div class="logbox-toolbar"><div><p>آخرین رویدادهای ثبت‌شده؛ برای دیدن رویدادهای جدید صفحه را تازه‌سازی کنید.</p><div class="logbox-filters" role="group" aria-label="فیلتر سطح لاگ"><button class="logbox-filter is-active" type="button" data-logbox-level="INFO" aria-pressed="true"><span class="logbox-level-dot level-info"></span>Info <b data-logbox-count="INFO">0</b></button><button class="logbox-filter is-active" type="button" data-logbox-level="WARNING" aria-pressed="true"><span class="logbox-level-dot level-warning"></span>Warning <b data-logbox-count="WARNING">0</b></button><button class="logbox-filter is-active" type="button" data-logbox-level="ERROR" aria-pressed="true"><span class="logbox-level-dot level-error"></span>Error <b data-logbox-count="ERROR">0</b></button></div></div><div class="logbox-toolbar-actions"><span class="logbox-filter-status" data-logbox-status aria-live="polite"></span><button class="btn btn-primary btn-sm" type="button" data-logbox-copy><i class="bi bi-copy" aria-hidden="true"></i> کپی لاگ‌های نمایش‌داده‌شده</button></div></div><pre class="logbox-output" dir="ltr" tabindex="0">'.htmlspecialchars($logs, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre></div></div></div></div></div>';
    }

    private function recentLogs(): string
    {
        $sections = [];
        foreach (['application.log', 'search-console.log'] as $name) {
            $path = $this->basePath.'/storage/logs/'.$name;
            if (!is_file($path) || !is_readable($path)) continue;
            $handle = @fopen($path, 'rb');
            if ($handle === false) continue;
            $size = (int) (@filesize($path) ?: 0);
            $offset = max(0, $size - 65536);
            if ($offset > 0) fseek($handle, $offset);
            $contents = stream_get_contents($handle);
            fclose($handle);
            if (!is_string($contents)) continue;
            if ($offset > 0 && ($newline = strpos($contents, "\n")) !== false) $contents = substr($contents, $newline + 1);
            $sections[] = '=== '.$name." ===\n".trim($contents);
        }
        return $sections === [] ? 'هنوز لاگی ثبت نشده است.' : implode("\n\n", $sections);
    }

    private function components(DOMDocument $document): void
    {
        foreach (iterator_to_array($document->getElementsByTagName('table')) as $table) {
            if (!$table instanceof DOMElement) continue;
            $table->setAttribute('class', trim($table->getAttribute('class').' table table-striped table-hover align-middle'));
            $parent = $table->parentNode;
            if (!$parent instanceof DOMElement || str_contains($parent->getAttribute('class'), 'table-responsive')) continue;
            $wrapper = $document->createElement('div');
            $wrapper->setAttribute('class', 'table-responsive seo-table-responsive');
            $parent->insertBefore($wrapper, $table); $wrapper->appendChild($table);
        }
        foreach (['input'=>'form-control','select'=>'form-select','textarea'=>'form-control'] as $tag=>$class) foreach (iterator_to_array($document->getElementsByTagName($tag)) as $element) if ($element instanceof DOMElement && $element->getAttribute('type') !== 'hidden' && $element->getAttribute('type') !== 'checkbox') $element->setAttribute('class', trim($element->getAttribute('class').' '.$class));
        foreach (iterator_to_array($document->getElementsByTagName('button')) as $button) if ($button instanceof DOMElement && !str_contains($button->getAttribute('class'), 'btn')) $button->setAttribute('class', trim($button->getAttribute('class').' btn btn-primary'));
        foreach (iterator_to_array($document->getElementsByTagName('a')) as $link) {
            if (!$link instanceof DOMElement) continue;
            $class = $link->getAttribute('class');
            if (str_contains($class, 'button') && !str_contains($class, 'btn')) {
                $link->setAttribute('class', trim($class.' btn btn-primary'));
            }
        }
        foreach (iterator_to_array($document->getElementsByTagName('form')) as $form) if ($form instanceof DOMElement) $form->setAttribute('class', trim($form->getAttribute('class').' adminlte-form'));
        foreach (iterator_to_array($document->getElementsByTagName('p')) as $paragraph) if ($paragraph instanceof DOMElement && str_contains($paragraph->getAttribute('class'), 'error')) $paragraph->setAttribute('class', trim($paragraph->getAttribute('class').' alert alert-danger'));
    }

    private function dependency(DOMDocument $document, DOMElement $parent, string $tag, array $attributes): void
    {
        $element = $document->createElement($tag); foreach ($attributes as $name=>$value) $element->setAttribute($name,$value); $parent->appendChild($element);
    }

    private function fragment(DOMDocument $document, string $html): DOMElement
    {
        $temporary = new DOMDocument('1.0','UTF-8'); $temporary->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        /** @var DOMElement $root */ $root = $temporary->documentElement;
        /** @var DOMElement */ return $document->importNode($root, true);
    }
}
