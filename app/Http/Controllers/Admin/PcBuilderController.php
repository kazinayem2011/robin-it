<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\PcBuilderHealth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the PC Builder is offering, and why.
 *
 * Nothing in the admin has ever said how a part reaches a builder slot. It is
 * the category the product is filed under and its Active tick — not stock, not
 * a flag on the builder, and there is no screen that mentions it. Compatibility
 * is driven by specification names that have to match, and a missing one counts
 * as "unknown" rather than a failure, so a build nobody could check looks
 * exactly like one that passed.
 *
 * Read-only, and deliberately so: everything here is changed on the product or
 * the category it already belongs to. This only explains where to go.
 */
class PcBuilderController extends Controller
{
    public function __construct(private readonly PcBuilderHealth $health) {}

    public function index(): Response
    {
        return Inertia::render('Admin/PcBuilder', [
            'slots' => $this->health->slots(),
            'summary' => $this->health->summary(),
        ]);
    }
}
