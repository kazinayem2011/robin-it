<?php

namespace Tests\Feature;

use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Vite as ViteFacade;
use Illuminate\Support\HtmlString;
use Tests\TestCase;

/**
 * The `Link` preload header has to stay small enough for the host to pass on.
 *
 * Every chunk a page imported went into it, and the product page imports the
 * most. Its header outgrew the 8 KB the production LiteSpeed accepts from PHP,
 * which answered every full load of every product with its own 500 page.
 * Inertia visits were fine — they come back as JSON with no such header — so
 * it only showed on a refresh, a shared link or a search result.
 */
class PreloadLinkHeaderTest extends TestCase
{
    public function test_a_page_with_many_chunks_still_sends_a_small_link_header(): void
    {
        ViteFacade::clearResolvedInstance();

        // As many chunks as the product page imports, and then some.
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null)
            {
                return new HtmlString('');
            }

            public function preloadedAssets()
            {
                return collect(range(1, 120))->mapWithKeys(fn ($i) => [
                    asset("build/assets/chunk-{$i}-AbCdEfGh.js") => ['rel="modulepreload"', 'as="script"'],
                ])->all();
            }
        });

        $link = $this->get('/products/any-product')->headers->get('Link');

        $this->assertNotNull($link, 'The page should still preload its first assets.');
        $this->assertLessThanOrEqual(24, substr_count($link, 'rel="modulepreload"'));
        $this->assertLessThan(4096, strlen($link));
    }
}
