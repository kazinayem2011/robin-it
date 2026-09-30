<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The form takes a spec value of up to 2,000 characters, so the column must
 * hold one. It held 255, and on MySQL a phone's long "Display features" row
 * turned the whole product save into a server error. SQLite, which these tests
 * run on, never enforces a VARCHAR's length — so this checks the type.
 */
class LongSpecValueTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_spec_value_is_a_text_column(): void
    {
        $this->assertSame('text', Schema::getColumnType('product_specifications', 'value'));
    }
}
