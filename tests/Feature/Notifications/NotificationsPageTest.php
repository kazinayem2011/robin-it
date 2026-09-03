<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every notification, which the bell is not.
 *
 * The bell holds the last twenty and offers one action — open it, which marks
 * it read on the way past. Anything older than a busy afternoon was gone from
 * view while still sitting in the table, with no way to mark one back to
 * unread, throw one away, or find the one about a particular order.
 *
 * The rows belong to whoever is signed in, and every route here is scoped by
 * the relation rather than by an id in the request, so the scoping tests below
 * are the ones that matter most.
 */
class NotificationsPageTest extends TestCase
{
    use RefreshDatabase;

    private function notify(User $user, array $data, bool $read = false): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\OrderPlaced',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode($data),
            'read_at' => $read ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function payload(string $kind = 'order.placed', string $title = 'New order ORD-1'): array
    {
        return [
            'kind' => $kind,
            'title' => $title,
            'body' => 'Something happened.',
            'url' => '/admin/orders',
            'icon' => 'order',
        ];
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/notifications')->assertRedirect('/login');
    }

    public function test_it_lists_what_this_person_was_sent(): void
    {
        $user = User::factory()->create();
        $this->notify($user, $this->payload());

        $this->actingAs($user)->get('/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Notifications/Index')
                ->has('notifications.data', 1)
                ->where('notifications.data.0.title', 'New order ORD-1')
                ->where('notifications.data.0.read', false)
                ->where('unread', 1)
            );
    }

    /** The rows are somebody's. Nobody else's appear, ever. */
    public function test_it_never_shows_somebody_elses(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $this->notify($theirs, $this->payload(title: 'Not yours'));

        $this->actingAs($mine)->get('/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('notifications.data', 0));
    }

    public function test_it_filters_by_read_and_unread(): void
    {
        $user = User::factory()->create();
        $this->notify($user, $this->payload(title: 'Unread one'));
        $this->notify($user, $this->payload(title: 'Read one'), read: true);

        $this->actingAs($user)->get('/notifications?status=unread')
            ->assertInertia(fn ($page) => $page
                ->has('notifications.data', 1)
                ->where('notifications.data.0.title', 'Unread one')
            );

        $this->actingAs($user)->get('/notifications?status=read')
            ->assertInertia(fn ($page) => $page
                ->has('notifications.data', 1)
                ->where('notifications.data.0.title', 'Read one')
            );
    }

    public function test_it_filters_by_kind(): void
    {
        $user = User::factory()->create();
        $this->notify($user, $this->payload('order.placed', 'An order'));
        $this->notify($user, $this->payload('stock.low', 'Low stock'));

        $this->actingAs($user)->get('/notifications?kind=stock.low')
            ->assertInertia(fn ($page) => $page
                ->has('notifications.data', 1)
                ->where('notifications.data.0.title', 'Low stock')
            );
    }

    /** Searching the words, which is how you find the one about an order. */
    public function test_it_searches_the_text(): void
    {
        $user = User::factory()->create();
        $this->notify($user, $this->payload(title: 'New order ORD-77'));
        $this->notify($user, $this->payload(title: 'New order ORD-88'));

        $this->actingAs($user)->get('/notifications?search=ORD-88')
            ->assertInertia(fn ($page) => $page
                ->has('notifications.data', 1)
                ->where('notifications.data.0.title', 'New order ORD-88')
            );
    }

    /** Only the kinds this person has had, so the filter has no dead ends. */
    public function test_the_kind_filter_offers_only_what_arrived(): void
    {
        $user = User::factory()->create();
        $this->notify($user, $this->payload('order.placed'));
        $this->notify($user, $this->payload('order.placed'));

        $this->actingAs($user)->get('/notifications')
            ->assertInertia(fn ($page) => $page
                ->has('kinds', 1)
                ->where('kinds.0', 'order.placed')
            );
    }

    public function test_one_can_be_marked_unread_again(): void
    {
        $user = User::factory()->create();
        $id = $this->notify($user, $this->payload(), read: true);

        $this->actingAs($user)->postJson("/api/notifications/{$id}/unread")
            ->assertOk()
            ->assertJsonPath('data.unread', 1);

        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    public function test_one_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $id = $this->notify($user, $this->payload());

        $this->actingAs($user)->deleteJson("/api/notifications/{$id}")->assertOk();

        $this->assertDatabaseMissing('notifications', ['id' => $id]);
    }

    /** Deleting is scoped the same way reading is. */
    public function test_somebody_elses_cannot_be_deleted(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $id = $this->notify($theirs, $this->payload());

        $this->actingAs($mine)->deleteJson("/api/notifications/{$id}")->assertOk();

        // Answered the same either way, and their row is untouched.
        $this->assertDatabaseHas('notifications', ['id' => $id]);
    }

    /**
     * Clearing takes the read ones and leaves the rest.
     *
     * Throwing away what nobody has looked at is the opposite of what somebody
     * tidying up means, and it is not recoverable.
     */
    public function test_clearing_keeps_the_unread_ones(): void
    {
        $user = User::factory()->create();
        $unread = $this->notify($user, $this->payload(title: 'Still to read'));
        $read = $this->notify($user, $this->payload(title: 'Done with'), read: true);

        $this->actingAs($user)->deleteJson('/api/notifications/clear-read')
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseHas('notifications', ['id' => $unread]);
        $this->assertDatabaseMissing('notifications', ['id' => $read]);
    }

    /** And it does not reach into anybody else's read ones. */
    public function test_clearing_leaves_other_people_alone(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $this->notify($mine, $this->payload(), read: true);
        $id = $this->notify($theirs, $this->payload(), read: true);

        $this->actingAs($mine)->deleteJson('/api/notifications/clear-read')
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseHas('notifications', ['id' => $id]);
    }

    /**
     * "clear-read" must not be read as an id.
     *
     * It is registered before the {notification} route for exactly this
     * reason; the wrong order would delete nothing and report success.
     */
    public function test_clearing_is_not_mistaken_for_a_notification_id(): void
    {
        $user = User::factory()->create();
        $this->notify($user, $this->payload(), read: true);

        $this->actingAs($user)->deleteJson('/api/notifications/clear-read')
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);
    }
}
