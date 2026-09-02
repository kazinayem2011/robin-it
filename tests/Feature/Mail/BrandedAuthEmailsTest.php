<?php

namespace Tests\Feature\Mail;

use App\Models\User;
use App\Support\BrandDetails;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The two emails the framework sends, in the shop's envelope.
 *
 * Every email this application writes itself extends emails/layouts/master.
 * These two did not, because Laravel sends them rather than us: signing up
 * produced the shop's welcome email and, moments earlier, a verification email
 * in the framework's stock template — different typeface, no logo, no hotline.
 * Password reset was the same, which is the message a customer has most reason
 * to be suspicious of.
 *
 * AppServiceProvider::brandFrameworkEmails() overrides the presentation and
 * nothing else; the URL, its signature and its expiry are still the
 * framework's, so these tests check the envelope and that the link survives.
 */
class BrandedAuthEmailsTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->create([
            'name' => 'Nayem Kazi',
            'email' => 'customer@example.com',
        ]);
    }

    public static function frameworkEmails(): array
    {
        return [
            'verify an address' => [VerifyEmail::class, null],
            'reset a password' => [ResetPassword::class, 'reset-token-123'],
        ];
    }

    /**
     * The marker is a class the shop's layout emits and Laravel's does not, so
     * this fails if either notification ever falls back to the stock template.
     */
    #[DataProvider('frameworkEmails')]
    public function test_it_is_sent_in_the_shop_layout(string $class, ?string $argument): void
    {
        $notification = $argument === null ? new $class : new $class($argument);

        $html = (string) $notification->toMail($this->customer())->render();

        $this->assertStringContainsString('eml-', $html, 'not rendered through emails/layouts/master');
        $this->assertStringContainsString(BrandDetails::name(), $html, 'the shop is not named');
        $this->assertStringNotContainsString(
            'Whoops!',
            $html,
            "Laravel's stock template is still being used."
        );
    }

    #[DataProvider('frameworkEmails')]
    public function test_the_subject_names_the_shop(string $class, ?string $argument): void
    {
        $notification = $argument === null ? new $class : new $class($argument);

        $this->assertStringContainsString(
            BrandDetails::name(),
            (string) $notification->toMail($this->customer())->subject
        );
    }

    /**
     * Both parts, and the link in each. A verification email whose HTML renders
     * and whose text part is empty arrives blank in a plain-text client.
     */
    #[DataProvider('frameworkEmails')]
    public function test_both_parts_render_and_carry_the_link(string $class, ?string $argument): void
    {
        $notification = $argument === null ? new $class : new $class($argument);
        $message = $notification->toMail($this->customer());

        $html = (string) $message->render();

        // text() folds both views into ->view as ['html' => …, 'text' => …].
        $this->assertIsArray($message->view, 'no plain-text part was registered');

        $text = view($message->view['text'], $message->viewData)->render();

        $this->assertNotEmpty($text, 'plain-text part is empty');

        // The action link is the entire point of both messages.
        foreach (['html' => $html, 'text' => $text] as $part => $body) {
            $this->assertMatchesRegularExpression(
                '~https?://[^\s"<]+~',
                $body,
                "the {$part} part carries no link"
            );
        }
    }

    /**
     * A verification link that has been signed and then HTML-escaped no longer
     * matches its signature, and the customer gets "Invalid signature" after
     * doing exactly what they were asked to.
     */
    public function test_the_verification_link_is_not_mangled_by_escaping(): void
    {
        $user = $this->customer();
        $message = (new VerifyEmail)->toMail($user);

        $url = $message->viewData['url'];
        $html = (string) $message->render();

        $this->assertStringContainsString('signature=', $url, 'the framework did not sign the URL');
        $this->assertStringContainsString(
            htmlspecialchars($url, ENT_QUOTES),
            $html,
            'the signed URL is not in the email intact'
        );
    }
}
