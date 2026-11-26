<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Keeps the small set of tags a rich-text block may contain and drops everything else,
 * so text typed in admin can never run scripts on a customer's page.
 */
class HtmlSanitizer
{
    private const ALLOWED = ['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'a', 'strong', 'b', 'em', 'i', 'u', 'br', 'blockquote'];

    /** Removed together with everything inside them. */
    private const DROPPED = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math', 'template', 'noscript', 'link', 'meta'];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="root">'.$html.'</div>', LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('root');
        if (! $root) {
            return '';
        }
        self::walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, self::DROPPED, true)) {
                    $node->removeChild($child);

                    continue;
                }
                self::walk($child);

                if (! in_array($tag, self::ALLOWED, true)) {
                    // Unknown tag: keep its (already cleaned) content, drop the tag itself.
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);

                    continue;
                }

                $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';
                foreach (iterator_to_array($child->attributes) as $attribute) {
                    $child->removeAttribute($attribute->name);
                }
                if ($tag === 'a') {
                    if (preg_match('~^(https?://|mailto:|tel:|/(?!/)|#)~i', $href)) {
                        $child->setAttribute('href', $href);
                        if (preg_match('~^https?://~i', $href)) {
                            $child->setAttribute('rel', 'noopener nofollow');
                            $child->setAttribute('target', '_blank');
                        }
                    }
                }
            } elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $node->removeChild($child);
            }
        }
    }
}
