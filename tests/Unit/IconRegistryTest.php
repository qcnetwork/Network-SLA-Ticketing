<?php

namespace Tests\Unit;

use App\Enums\TicketStatus;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The JS bundle only registers the Lucide icons listed in resources/js/icons.js,
 * so an icon used in a view but missing there silently renders as nothing.
 */
class IconRegistryTest extends TestCase
{
    public function test_every_icon_used_in_views_and_enums_is_registered(): void
    {
        $root = dirname(__DIR__, 2);
        $registry = file_get_contents($root.'/resources/js/icons.js');

        $used = array_map(fn (TicketStatus $status) => $status->icon(), TicketStatus::cases());
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/views'));
        foreach ($files as $file) {
            if ($file->isFile()) {
                preg_match_all("/data-lucide=\"([a-z0-9-]+)\"|'icon' => '([a-z0-9-]+)'|, '([a-z0-9-]+)', 'text-/", file_get_contents($file->getPathname()), $matches);
                $used = array_merge($used, array_filter(array_merge($matches[1], $matches[2], $matches[3])));
            }
        }

        $missing = array_values(array_filter(array_unique($used), function (string $icon) use ($registry) {
            $pascal = str_replace(' ', '', ucwords(str_replace('-', ' ', $icon)));

            return ! preg_match('/\b'.$pascal.',/', $registry);
        }));

        $this->assertSame([], $missing, 'Tambahkan ikon ini ke resources/js/icons.js');
    }
}
