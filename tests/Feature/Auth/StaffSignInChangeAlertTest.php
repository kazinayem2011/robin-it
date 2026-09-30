<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\StaffSignInChanged;
use App\Services\SmsService;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

/**
 * The owner hears whenever a staff or admin sign-in changes.
 *
 * The owner's password once changed with nobody on the shop's side having
 * done it. Whoever can change a sign-in can take the admin, so the change
 * itself is announced — to the owner, never to customers.
 */
class StaffSignInChangeAlertTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Roles::forget();
        Notification::fake();
        $this->sms = $this->spy(SmsService::class);

        $this->owner = User::factory()->create([
            'name' => 'Robin', 'email' => 'owner@shop.test', 'phone' => '01818176783',
        ]);
        $this->owner->forceFill(['role' => User::ROLE_ADMIN])->save();
    }

    private function staff(string $role = 'manager'): User
    {
        $user = User::factory()->create(['name' => 'Nazmul', 'email' => 'nazmul@shop.test', 'phone' => '01711000001']);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    public function test_a_staff_password_change_is_told_to_the_owner_on_the_bell_email_and_text(): void
    {
        $manager = $this->staff();

        $this->actingAs($this->owner);
        $manager->forceFill(['password' => Hash::make('New-Passw0rd!')])->save();

        Notification::assertSentTo($this->owner, StaffSignInChanged::class, function ($n, $channels) use ($manager) {
            return $n->account->is($manager)
                && $n->changed === ['password']
                && $n->by === 'Robin'
                && in_array('mail', $channels, true)
                && in_array('database', $channels, true);
        });
        Notification::assertNotSentTo($manager, StaffSignInChanged::class);

        $this->sms->shouldHaveReceived('sendEvent')->with(
            'staff_signin_changed',
            '01818176783',
            Mockery::on(fn ($t) => str_contains($t, 'Nazmul (Manager)') && str_contains($t, 'password') && SmsService::parts($t) <= 2)
        )->once();
    }

    /** The case that started this: the owner's own password. */
    public function test_the_owners_own_change_is_told_to_the_owner(): void
    {
        $this->owner->forceFill(['password' => Hash::make('Another-Passw0rd!')])->save();

        Notification::assertSentTo($this->owner, StaffSignInChanged::class, fn ($n) => $n->account->is($this->owner)
            && $n->by === 'the server, with nobody signed in');
    }

    public function test_an_email_change_names_the_old_and_new_address(): void
    {
        $manager = $this->staff();

        $this->actingAs($manager);
        $manager->update(['email' => 'someone-else@mail.test']);

        Notification::assertSentTo($this->owner, StaffSignInChanged::class, function ($n) {
            $text = implode(' ', $n->lines());

            return $n->changed === ['email']
                && str_contains($text, 'nazmul@shop.test')
                && str_contains($text, 'someone-else@mail.test')
                && str_contains($n->by, 'their own account');
        });
    }

    public function test_a_customer_changing_their_password_tells_nobody(): void
    {
        $customer = User::factory()->create(['phone' => '01799000111']);

        $customer->forceFill(['password' => Hash::make('Customer-Passw0rd!')])->save();

        Notification::assertNothingSent();
        $this->sms->shouldNotHaveReceived('sendEvent', ['staff_signin_changed', Mockery::any(), Mockery::any()]);
    }

    public function test_other_changes_to_a_staff_account_send_nothing(): void
    {
        $manager = $this->staff();

        $manager->forceFill(['name' => 'Nazmul Hasan', 'last_login_at' => now()])->save();

        Notification::assertNothingSent();
    }

    public function test_a_suspended_owner_is_not_told(): void
    {
        $second = User::factory()->create(['email' => 'old-owner@shop.test']);
        $second->forceFill(['role' => User::ROLE_ADMIN, 'is_active' => false])->save();
        $manager = $this->staff();

        $manager->forceFill(['password' => Hash::make('New-Passw0rd!')])->save();

        Notification::assertSentTo($this->owner, StaffSignInChanged::class);
        Notification::assertNotSentTo($second, StaffSignInChanged::class);
    }

    public function test_the_text_can_be_switched_off_in_settings(): void
    {
        $this->assertArrayHasKey('staff_signin_changed', SmsService::EVENTS);
        $this->assertContains('sms_on_staff_signin_changed', SmsService::KEYS);
    }
}
