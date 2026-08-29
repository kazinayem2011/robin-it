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
                'slug' => 'contact',
                'title' => 'Contact us',
                'subtitle' => 'A question about an order, a part, or a warranty — write to us and a person will answer.',
                'meta_description' => 'Get in touch with Robins Computer by phone, email or from any showroom.',
                'body' => '',
            ],
            [
                'slug' => 'privacy',
                'title' => 'Privacy policy',
                'subtitle' => 'What we collect, and what we do with it',
                'meta_description' => 'What Robins Computer collects, why, and how to have it removed.',
                'body' => <<<'HTML'
<h2>What we collect</h2>
<p>When you place an order we take your name, mobile number and delivery address. A courier cannot deliver without them. If you open an account we also keep your email address, and if you join our mailing list we keep that address until you leave the list.</p>
<p>We record what you bought, what you paid and how you paid it. Where a product carries a serial number we record which unit went to you, because that is what a warranty claim is checked against years later.</p>

<h2>Your mobile number</h2>
<p>We confirm your number by sending a six-digit code to it when you register, and again if you ever need to reset your password. The code is valid for a few minutes and we will never ring you and ask you to read one out. If somebody claiming to be us does that, it is not us.</p>
<p>We text you when your order is received, and when money is owed on delivery so the cash is ready when the rider knocks. Whether we also text you on dispatch, delivery, cancellation or refund is a setting in our own system; your courier usually texts you about the parcel itself.</p>

<h2>Who else sees it</h2>
<p>Your name, number and address go to the courier carrying your parcel &mdash; Pathao, Steadfast, RedX or whoever else is on your delivery &mdash; because that is how it reaches you. Your number also goes to the gateway that sends our text messages. Nobody else receives your details, and we do not sell them.</p>

<h2>How long we keep it</h2>
<p>Order and warranty records are kept for as long as the warranty on what you bought could still be claimed, and afterwards for as long as our accounts require. Verification codes are deleted within a month. A mailing list address is deleted when you leave the list.</p>

<h2>Cookies</h2>
<p>We set a cookie to keep you signed in and to remember what is in your basket. There is nothing in it that identifies you to anybody else.</p>

<h2>Your choices</h2>
<p>Every marketing email carries a link that takes you off the list in one click. You can ask us to correct your details, or to remove what we are not required to keep, by writing to us from the contact page or ringing our hotline. We will ask you to confirm who you are before changing anything.</p>
HTML,
            ],
            [
                'slug' => 'terms',
                'title' => 'Terms & conditions',
                'subtitle' => 'The terms you buy under',
                'meta_description' => 'The terms that apply when you order from Robins Computer.',
                'body' => <<<'HTML'
<h2>Placing an order</h2>
<p>An order is an offer to buy. It becomes a contract when we confirm it, which we do once we have checked the item is genuinely in stock and the delivery details are usable. Until then we may decline it and you owe us nothing.</p>
<p>Where a price or a specification is shown in error we will tell you before taking payment, and you are free to walk away. Computer parts change price often, and a listing that is wrong is wrong &mdash; not a price we are bound to.</p>

<h2>Paying</h2>
<p>Prices are in Bangladeshi taka. Where VAT applies it is shown on your invoice.</p>
<p>You may pay in full, pay a deposit and settle the balance on delivery, or pay the whole amount on delivery where we offer that. A build or a special order may require a deposit before we start, and that deposit covers work we have already done &mdash; if you cancel after we have begun, we may keep the part of it that covers what was spent.</p>

<h2>Delivery</h2>
<p>We hand parcels to a third-party courier. Delivery charges are shown at checkout and depend on where you are. We give the courier a collection date, not a delivery guarantee: once a parcel is with them, the timing is theirs.</p>
<p>Please check the parcel in front of the rider. Damage in transit is far easier to settle when it is reported at the door than a week later.</p>

<h2>Warranty</h2>
<p>Warranty is the manufacturer's, for the period stated on the product page, and we handle the claim for you. Bring the unit with its serial number and your invoice; we check the serial against our own record of which unit we sold you.</p>
<p>A warranty does not cover physical damage, liquid damage, damage from unstable mains power, a removed or altered serial number, or a part that has been opened or modified by somebody else. Consumables and normal wear are not covered.</p>

<h2>Your account</h2>
<p>Keep your password to yourself and keep the mobile number on your account current &mdash; it is how we reach you about an order and how you get back in if you forget your password. You are responsible for what happens under your account.</p>

<h2>What we are responsible for</h2>
<p>If we get something wrong we will put it right: repair, replace, or refund. We are not liable for loss of data, loss of business, or any loss beyond the value of what you bought from us. Nothing here removes a right you have under Bangladeshi consumer law.</p>

<h2>Which law applies</h2>
<p>These terms are governed by the law of Bangladesh, and the courts of Dhaka have jurisdiction.</p>
HTML,
            ],
            [
                'slug' => 'return-policy',
                'title' => 'Returns & refunds',
                'subtitle' => 'Changing your mind, and what to do when something is wrong',
                'meta_description' => 'How to return an item to Robins Computer and how refunds are handled.',
                'body' => <<<'HTML'
<h2>Changing your mind</h2>
<p>Tell us within <strong>7 days</strong> of delivery and return the item unused, in its original packaging, with every cable, adapter and accessory it came with. Return carriage is yours to pay unless the item was wrong or faulty.</p>
<p>Some things cannot be returned once opened, and this is not us being awkward &mdash; we cannot resell them: software and anything with a licence key that has been revealed, and any item whose anti-tamper seal is broken.</p>

<h2>If it arrives faulty</h2>
<p>Tell us within <strong>72 hours</strong> of delivery and we treat it as dead on arrival: we collect it at our cost and send a replacement or refund you in full. After 72 hours a fault is a warranty claim, which we also handle &mdash; it is simply the manufacturer's process rather than ours, so it takes longer.</p>

<h2>If we sent the wrong thing</h2>
<p>Our mistake, our cost. We collect it and send the right item, and you pay nothing either way.</p>

<h2>How a refund reaches you</h2>
<p>Once we have the item back and have checked it, we record the refund against your order and text you to say it is on its way. Cash and mobile-money refunds are usually with you the same day. A bank transfer takes a few working days to appear, and a card refund follows your bank's own timetable &mdash; we cannot make either move faster, which is why we tell you the moment we have sent it.</p>
<p>Where you paid a delivery charge and the return is our fault, we refund that too. Where you simply changed your mind, we refund the goods and not the carriage.</p>

<h2>Built-to-order machines</h2>
<p>A machine assembled to your own specification is not returnable for a change of mind, because the parts cannot go back on the shelf as new. It is of course covered if a component in it is faulty.</p>

<h2>Starting a return</h2>
<p>Ring our hotline, write from the contact page, or bring the item to any of our showrooms. Have your order number ready &mdash; and the serial number, if the item carries one.</p>
HTML,
            ],
        ];
    }
}
