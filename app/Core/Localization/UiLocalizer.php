<?php

declare(strict_types=1);

namespace App\Core\Localization;

use App\Core\Http\Response;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Throwable;

final class UiLocalizer
{
    private Translator $english;
    private Translator $localized;
    private Translator $validation;
    private Terminology $terms;

    public function __construct(private readonly string $locale, private readonly string $basePath)
    {
        $this->english = new Translator('en', $basePath, 'ui');
        $this->localized = new Translator($locale, $basePath, 'ui');
        $this->validation = new Translator($locale, $basePath, 'validation');
        $this->terms = new Terminology($locale, $basePath);
    }

    public function response(Response $response): Response
    {
        $type = strtolower((string) ($response->headers['Content-Type'] ?? ''));
        if (str_contains($type, 'text/html')) return new Response($this->html($response->body), $response->status, $response->headers);
        if (str_contains($type, 'application/json')) return new Response($this->json($response->body), $response->status, $response->headers);
        return $response;
    }

    public function html(string $html): string
    {
        if ($html === '' || $this->locale !== 'fa') return $html;
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            foreach (iterator_to_array($document->getElementsByTagName('html')) as $root) {
                $root->setAttribute('lang', 'fa'); $root->setAttribute('dir', 'rtl');
            }
            $texts = [];
            $walk = static function (DOMNode $node) use (&$walk, &$texts): void {
                if ($node instanceof DOMElement && in_array(strtolower($node->tagName), ['script','style','code','pre'], true)) return;
                if ($node instanceof DOMText) $texts[] = $node;
                foreach (iterator_to_array($node->childNodes) as $child) $walk($child);
            };
            $walk($document);
            foreach ($texts as $text) $this->translateText($document, $text);
            $this->directions($document);
            $this->assets($document);
            return str_replace('<?xml encoding="UTF-8">', '', (string) $document->saveHTML());
        } catch (Throwable) {
            return $html;
        } finally {
            libxml_clear_errors(); libxml_use_internal_errors($previous);
        }
    }

    private function json(string $json): string
    {
        if ($this->locale !== 'fa') return $json;
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            $translate = function (mixed $value) use (&$translate): mixed {
                if (is_array($value)) { foreach ($value as $key => $item) $value[$key] = $translate($item); return $value; }
                return is_string($value) ? $this->translate($value) : $value;
            };
            return (string) json_encode($translate($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Throwable) { return $json; }
    }

    private function translateText(DOMDocument $document, DOMText $node): void
    {
        $raw = $node->nodeValue ?? ''; $trimmed = trim($raw);
        if ($trimmed === '') return;
        $translated = $this->translate($trimmed);
        if ($translated !== $trimmed) $node->nodeValue = str_replace($trimmed, $translated, $raw);
        $entry = $this->terms->forLabel($translated);
        $parent = $node->parentNode;
        if ($entry === null || !$parent instanceof DOMElement || !in_array(strtolower($parent->tagName), ['label','th','dt','h1','h2','h3','span','p'], true)) return;
        $id = 'term-' . substr(hash('sha256', $entry['term'] . spl_object_id($node)), 0, 12);
        $wrap = $document->createElement('span'); $wrap->setAttribute('class', 'term-help');
        $button = $document->createElement('button', 'ⓘ');
        foreach (['type'=>'button','class'=>'term-trigger','aria-label'=>'English: ' . $entry['term'],'aria-describedby'=>$id,'aria-expanded'=>'false'] as $name => $value) $button->setAttribute($name, $value);
        $tip = $document->createElement('span'); $tip->setAttribute('id', $id); $tip->setAttribute('class', 'term-tooltip'); $tip->setAttribute('role', 'tooltip'); $tip->setAttribute('dir', 'ltr'); $tip->appendChild($document->createTextNode($entry['term'] . (isset($entry['description']) ? ' — ' . $entry['description'] : '')));
        $wrap->appendChild($button); $wrap->appendChild($tip); $parent->insertBefore($wrap, $node->nextSibling);
    }

    private function translate(string $source): string
    {
        foreach ($this->englishMessages() as $key => $english) {
            if ($source === $english) return PersianNormalizer::ui($this->localized->has($key) ? $this->localized->get($key) : $this->validation->get($key));
        }
        if (str_ends_with($source, ' — SEO Tracker')) {
            $head = substr($source, 0, -strlen(' — SEO Tracker')); $translated = $this->translate($head);
            if ($translated !== $head) return $translated . ' — SEO Tracker';
        }
        if (str_starts_with($source, '— ')) {
            $tail = substr($source, strlen('— ')); $translated = $this->translate($tail);
            if ($translated !== $tail) return '— ' . $translated;
        }
        return $source;
    }

    /** @return array<string,string> */
    private function englishMessages(): array
    {
        static $cache = null;
        if (is_array($cache)) return $cache;
        $file = $this->basePath . '/lang/en/ui.php'; $messages = is_file($file) ? require $file : [];
        $validation = $this->basePath . '/lang/en/validation.php';
        if (is_file($validation)) $messages += require $validation;
        return $cache = $messages;
    }

    private function directions(DOMDocument $document): void
    {
        foreach (iterator_to_array($document->getElementsByTagName('*')) as $element) {
            if (!$element instanceof DOMElement) continue;
            $tag = strtolower($element->tagName); $type = strtolower($element->getAttribute('type'));
            if (in_array($tag, ['code','pre'], true) || ($tag === 'input' && in_array($type, ['email','url','number'], true))) $element->setAttribute('dir', 'ltr');
            $text = trim($element->textContent);
            if (in_array($tag, ['td','dd','a'], true) && preg_match('~^(?:https?://|[\w.+-]+@[\w.-]+\.|(?:\d{1,3}\.){3}\d{1,3}$)~i', $text)) $element->setAttribute('dir', 'ltr');
        }
    }

    private function assets(DOMDocument $document): void
    {
        $heads = $document->getElementsByTagName('head'); if ($heads->length < 1) return;
        $script = $document->createElement('script'); $script->setAttribute('src', '/assets/tooltips.js'); $script->setAttribute('defer', 'defer'); $heads->item(0)?->appendChild($script);
    }
}
