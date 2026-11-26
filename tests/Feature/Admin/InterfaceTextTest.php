<?php

namespace Tests\Feature\Admin;

use App\Console\Commands\ScanUiText;
use App\Models\Product;
use App\Services\UiText;
use Inertia\Testing\AssertableInertia;

class InterfaceTextTest extends AdminTestCase
{
    public function test_the_saved_list_of_interface_text_is_up_to_date(): void
    {
        // If this fails, run: php artisan ui:scan
        $this->assertSame(ScanUiText::scan(), UiText::registry());
        $this->assertGreaterThan(250, count(UiText::all()));
    }

    public function test_rewording_a_text_changes_the_storefront(): void
    {
        $admin = $this->makeAdmin('admin');
        $product = Product::factory()->create(['status' => 'active']);

        $this->get("/product/{$product->slug}")->assertOk()->assertSee('Add to cart')->assertDontSee('ui-text', false);

        $this->signIn($admin)->get('/admin/settings/text')->assertInertia(fn (AssertableInertia $p) => $p->component('Settings/Text')->has('areas', count(UiText::registry())));
        $this->signIn($admin)->put('/admin/settings/text', ['changes' => [
            ['default' => 'Add to cart', 'text' => 'Get it now'],
            ['default' => 'Something went wrong. Please try again.', 'text' => 'That did not work <b>sorry</b>'],
            ['default' => 'Not a text of this store', 'text' => 'Ignored'],
        ]])->assertSessionHasNoErrors();
        auth('admin')->logout();

        $this->assertSame('Get it now', t('Add to cart'));
        $this->assertArrayNotHasKey('Not a text of this store', UiText::overrides());
        $page = $this->get("/product/{$product->slug}")->assertOk()->assertSee('Get it now');
        // The page scripts get the rewordings too, as data that cannot run as code.
        $page->assertSee('<script type="application/json" id="ui-text">', false)->assertSee('Get it now')->assertDontSee('<b>sorry</b>', false);

        // An empty field goes back to the original text.
        $this->signIn($admin)->put('/admin/settings/text', ['changes' => [['default' => 'Add to cart', 'text' => '']]])->assertSessionHasNoErrors();
        $this->assertSame('Add to cart', t('Add to cart'));
    }

    public function test_a_rewording_keeps_the_words_the_store_fills_in(): void
    {
        $admin = $this->makeAdmin('admin');

        $this->signIn($admin)->put('/admin/settings/text', ['changes' => [['default' => 'Based on :count reviews', 'text' => 'Many reviews']]])->assertSessionHasErrors('changes.0.text');
        $this->signIn($admin)->put('/admin/settings/text', ['changes' => [['default' => 'Based on :count reviews', 'text' => ':count people rated this']]])->assertSessionHasNoErrors();
        $this->assertSame('12 people rated this', t('Based on :count reviews', ['count' => 12]));

        $this->signIn($this->makeAdmin('moderator'))->get('/admin/settings/text')->assertForbidden();
    }
}
