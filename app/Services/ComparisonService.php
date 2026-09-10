<?php

namespace App\Services;

use App\Models\Comparison;
use Illuminate\Support\Facades\DB;

class ComparisonService
{
    /**
     * Move a guest's comparison table onto their account at login.
     *
     * The same problem the cart had, and a worse version of it: index() reads
     * by user_id the moment there is an account, so the session rows stopped
     * being shown — and because login regenerates the session id, nothing
     * could ever reach them again. A shopper who lined up four graphics cards
     * and then signed in to buy one found an empty table.
     */
    public function mergeGuestList(int $userId, ?string $sessionId): void
    {
        if (! $sessionId) {
            return;
        }

        DB::transaction(function () use ($userId, $sessionId) {
            $guestRows = Comparison::where('session_id', $sessionId)
                ->whereNull('user_id')
                ->orderBy('id')
                ->get();

            if ($guestRows->isEmpty()) {
                return;
            }

            $alreadyOnAccount = Comparison::where('user_id', $userId)
                ->pluck('product_id')
                ->all();

            $room = Comparison::MAX_ITEMS - count($alreadyOnAccount);

            foreach ($guestRows as $row) {
                // Already on the account: the guest row is the duplicate, and
                // it goes rather than being moved on top of what is there.
                if (in_array($row->product_id, $alreadyOnAccount, true)) {
                    continue;
                }

                if ($room <= 0) {
                    continue;
                }

                // Merging must never fail the login, so the cap is applied by
                // leaving the surplus behind rather than refusing the lot.
                $row->forceFill(['user_id' => $userId, 'session_id' => null])->save();

                $alreadyOnAccount[] = $row->product_id;
                $room--;
            }

            // Whatever did not move was a duplicate or past the cap; either
            // way it belongs to a session nobody can reach again.
            Comparison::where('session_id', $sessionId)->whereNull('user_id')->delete();
        });
    }
}
