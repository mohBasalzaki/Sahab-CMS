<?php
declare(strict_types=1);

namespace Sahab;

/** Whitelist-based HTML sanitiser for rich-text fields. */
final class Html
{
    private const TAGS = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'h2' => [], 'h3' => [], 'h4' => [],
        'a' => ['href', 'title', 'target', 'rel'], 'img' => ['src', 'alt', 'width', 'height'],
        'pre' => [], 'code' => [], 'hr' => [], 'figure' => [], 'figcaption' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
    ];
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'svg', 'math', 'base', 'noscript', 'template'];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '';
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $dom->getElementById('__root');
        if (!$root) return htmlspecialchars(strip_tags($html));
        self::walk($root);
        $out = '';
        foreach ($root->childNodes as $c) $out .= $dom->saveHTML($c);
        return trim($out);
    }

    private static function walk(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) continue;
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) { $node->removeChild($child); continue; }
            self::walk($child);
            if (!isset(self::TAGS[$tag])) {
                // unwrap: keep children
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $n = strtolower($attr->name);
                if (!in_array($n, self::TAGS[$tag], true)) { $child->removeAttribute($attr->name); continue; }
                if (in_array($n, ['href', 'src'], true) && !self::safeUrl($attr->value, $n === 'src')) {
                    $child->removeAttribute($attr->name);
                }
            }
            if ($tag === 'a' && $child->getAttribute('target') === '_blank') $child->setAttribute('rel', 'noopener noreferrer');
            if ($tag === 'a' && !$child->hasAttribute('href') ) { /* keep text */ }
        }
    }

    private static function safeUrl(string $u, bool $isImg): bool
    {
        $u = trim(preg_replace('/[\x00-\x20]+/', '', $u));
        if ($u === '') return false;
        if (preg_match('#^(https?:)?//#i', $u) || str_starts_with($u, '/') || str_starts_with($u, '#')) return true;
        if (!$isImg && preg_match('#^(mailto|tel):#i', $u)) return true;
        return !preg_match('#^[a-z][a-z0-9+.\-]*:#i', $u); // relative
    }
}
