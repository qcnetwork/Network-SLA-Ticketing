<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The Content-Security-Policy (SecurityHeaders) allows no inline script, so an onclick="..." or onchange="..."
 * attribute silently does nothing in the browser. Views use Alpine (@click, @change) instead.
 */
class NoInlineEventHandlersTest extends TestCase
{
    public function test_views_have_no_inline_event_handler_attributes(): void
    {
        $views = dirname(__DIR__, 2).'/resources/views';
        $found = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views)) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            foreach (file($file->getPathname()) as $number => $line) {
                if (preg_match('/\son[a-z]+\s*=\s*["\']/', $line)) {
                    $found[] = str_replace($views.DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $found, 'Ganti atribut on...="" dengan Alpine (@click / @change): CSP memblokir script inline');
    }
}
