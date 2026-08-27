<?php

namespace Database\Seeders;

use App\Models\ContentPage;
use Illuminate\Database\Seeder;

/**
 * Starting text for the pages the footer links to.
 *
 * Written to be true of this shop and plainly worded, but it is a starting
 * point rather than legal advice: the privacy, terms and returns pages should
 * be read and adjusted by whoever is accountable for them.
 */
class ContentPageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->pages() as $page) {
            // firstOrCreate, never update: re-running a seeder must not
            // overwrite what the shop has since written.
            ContentPage::firstOrCreate(
                ['slug' => $page['slug']],
                $page + ['is_published' => true, 'is_system' => true]
            );
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function pages(): array
    {
        return [
            [
                'slug' => 'about',
                'title' => 'About us',
                'subtitle' => 'The store of technology',
                'meta_description' => 'Who Robins Computer is, what we sell, and what we promise after the sale.',
                'body' => <<<'HTML'
<p>We sell the components, laptops and complete machines that people in Bangladesh actually build with — and we stand behind every one of them after the sale.</p>
<h2>What we promise</h2>
<p><strong>Genuine, with the warranty to prove it.</strong> Every part is sourced through the authorised channel and carries the manufacturer's warranty. Claims are handled here, not sent abroad.</p>
<p><strong>Built and tested before it ships.</strong> Complete machines are assembled, stress-tested and updated in our workshop. You get a PC that has already been switched on.</p>
<p><strong>Delivered across all 64 districts.</strong> Cash on delivery nationwide, with a tracking link from the courier the moment your parcel is handed over.</p>
<p><strong>Advice from people who use this kit.</strong> Ask what will fit, what is worth the money, and what is not. The answer comes from someone who builds these every day.</p>
HTML,
            ],
            [
                'slug' => 'privacy',
                'title' => 'Privacy policy',
                'subtitle' => 'What we collect, and what we do with it',
                'meta_description' => 'What Robins Computer collects, why, and how to have it removed.',
                'body' => <<<'HTML'
<p><em>Please review this text and adjust it to match how your shop actually operates before relying on it.</em></p>
<h2>What we collect</h2>
<p>When you place an order we collect your name, mobile number and delivery address, because a courier cannot deliver without them. If you create an account we also keep your email address. If you join our mailing list we keep that address until you leave the list.</p>
<h2>What we do with it</h2>
<p>We use it to take payment, deliver your order, answer your questions and handle warranty claims. We give your name, number and address to the courier carrying your parcel, because that is how it reaches you.</p>
<h2>What we do not do</h2>
<p>We do not sell your details, and we do not pass them to anyone who is not part of getting your order to you.</p>
<h2>Your choices</h2>
<p>Every marketing email carries a link that takes you off the list in one click. You can ask us to correct or remove your details by writing to us from the contact page.</p>
HTML,
            ],
            [
                'slug' => 'terms',
                'title' => 'Terms & conditions',
                'subtitle' => 'The terms you buy under',
                'meta_description' => 'The terms that apply when you order from Robins Computer.',
                'body' => <<<'HTML'
<p><em>Please review this text and adjust it to match how your shop actually operates before relying on it.</em></p>
<h2>Orders</h2>
<p>An order is an offer to buy. We confirm it once we have checked that the item is in stock and the delivery details are usable. Where a price or a specification is shown in error, we will tell you before we take payment and you are free to walk away.</p>
<h2>Prices and payment</h2>
<p>Prices are in Bangladeshi Taka and include VAT where it applies. Cash on delivery means payment is due to the courier when the parcel arrives.</p>
<h2>Delivery</h2>
<p>We deliver across Bangladesh. Delivery times are estimates, not promises, and depend on the courier.</p>
<h2>Warranty</h2>
<p>Products carry the manufacturer's warranty for the period stated on the product page. Warranty does not cover physical damage, liquid damage, or fault caused by use outside the manufacturer's specification.</p>
HTML,
            ],
            [
                'slug' => 'return-policy',
                'title' => 'Returns & refunds',
                'subtitle' => 'Changing your mind, and what to do when something is wrong',
                'meta_description' => 'How to return an item to Robins Computer and how refunds are handled.',
                'body' => <<<'HTML'
<p><em>Please review this text and adjust it to match how your shop actually operates before relying on it.</em></p>
<h2>Changing your mind</h2>
<p>Tell us within 7 days of delivery and return the item unused, in its original packaging, with everything it came with. Software, and anything sold with a licence key that has been revealed, cannot be returned once opened.</p>
<h2>When something is wrong</h2>
<p>If an item arrives faulty or is not what you ordered, tell us and we will collect it at our cost and replace or refund it.</p>
<h2>Refunds</h2>
<p>Refunds go back the way the money came. Cash on delivery orders are refunded by bank transfer or mobile financial service, to an account in the name the order was placed under.</p>
<h2>How to start a return</h2>
<p>Write to us from the contact page with your order number and what is wrong, and we will tell you what happens next.</p>
HTML,
            ],
        ];
    }
}
