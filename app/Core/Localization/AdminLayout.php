<?php

declare(strict_types=1);

namespace App\Core\Localization;

use App\Core\Security\Csrf;
use DOMDocument;
use DOMElement;

/** Applies the shared AdminLTE shell without changing controller workflows. */
final class AdminLayout
{
    private const ADMINLTE = '4.0.0-rc4';
    private const BOOTSTRAP_ICONS = '1.11.3';
    private const BOOTSTRAP = '5.3.3';

    public function __construct(private readonly string $basePath) {}

    public function apply(DOMDocument $document, string $path, array $context): void
    {
        $head = $document->getElementsByTagName('head')->item(0);
        $body = $document->getElementsByTagName('body')->item(0);
        if (!$head instanceof DOMElement || !$body instanceof DOMElement) return;

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
        $main = $document->getElementsByTagName('main')->item(0);
        if (!$main instanceof DOMElement) return;
        $main->setAttribute('class', trim($main->getAttribute('class').' app-main card border-0 shadow-sm p-4'));

        $wrapper = $document->createElement('div'); $wrapper->setAttribute('class', 'app-wrapper');
        $body->insertBefore($wrapper, $body->firstChild);
        $wrapper->appendChild($this->fragment($document, $this->navbar($context)));
        $wrapper->appendChild($this->fragment($document, $this->sidebar($path, (array)($context['permissions'] ?? []), (array)($context['modules'] ?? []))));
        $main->parentNode?->removeChild($main); $wrapper->appendChild($main);
        $footer = $document->createElement('footer'); $footer->setAttribute('class', 'app-footer text-center');
        $footer->appendChild($document->createTextNode('SEO Tracker · نسخه '.(string)($context['version'] ?? '')));
        $wrapper->appendChild($footer);

        $title = $document->getElementsByTagName('h1')->item(0);
        if ($title instanceof DOMElement) {
            $header = $document->createElement('div'); $header->setAttribute('class', 'app-content-header mb-4 border-bottom pb-3');
            $title->parentNode?->insertBefore($header, $title); $header->appendChild($title);
        }
        $this->components($document);
    }

    private function navbar(array $context): string
    {
        $name = htmlspecialchars((string)($context['user']['name'] ?? 'کاربر'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $token = htmlspecialchars(Csrf::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<nav class="app-header navbar navbar-expand bg-body shadow-sm"><div class="container-fluid"><button class="btn btn-link" type="button" data-lte-toggle="sidebar" aria-label="باز و بسته کردن منو"><i class="bi bi-list" aria-hidden="true"></i></button><span class="navbar-brand fw-bold">SEO Tracker</span><div class="me-auto d-flex align-items-center gap-3"><a class="nav-link" href="/account"><i class="bi bi-person-circle" aria-hidden="true"></i> '.$name.'</a><form method="post" action="/logout"><input type="hidden" name="_token" value="'.$token.'"><button class="btn btn-outline-secondary btn-sm" type="submit">خروج</button></form></div></div></nav>';
    }

    private function sidebar(string $path, array $permissions, array $modules): string
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
            $links .= '<li class="nav-item"><a class="nav-link'.$active.'" href="'.$href.'"><i class="nav-icon bi '.$icon.'" aria-hidden="true"></i><p>'.$label.'</p></a></li>';
        }
        return '<aside class="app-sidebar bg-dark shadow" data-bs-theme="dark"><div class="sidebar-brand"><a class="brand-link" href="/account"><span class="brand-text fw-semibold">SEO Tracker</span></a></div><div class="sidebar-wrapper"><nav class="mt-2" aria-label="منوی اصلی"><ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="menu">'.$links.'</ul></nav></div></aside>';
    }

    private function components(DOMDocument $document): void
    {
        foreach (iterator_to_array($document->getElementsByTagName('table')) as $table) if ($table instanceof DOMElement) $table->setAttribute('class', trim($table->getAttribute('class').' table table-striped table-hover align-middle'));
        foreach (['input'=>'form-control','select'=>'form-select','textarea'=>'form-control'] as $tag=>$class) foreach (iterator_to_array($document->getElementsByTagName($tag)) as $element) if ($element instanceof DOMElement && $element->getAttribute('type') !== 'hidden' && $element->getAttribute('type') !== 'checkbox') $element->setAttribute('class', trim($element->getAttribute('class').' '.$class));
        foreach (iterator_to_array($document->getElementsByTagName('button')) as $button) if ($button instanceof DOMElement && !str_contains($button->getAttribute('class'), 'btn')) $button->setAttribute('class', trim($button->getAttribute('class').' btn btn-primary'));
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
