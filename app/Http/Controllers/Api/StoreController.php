<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;

class StoreController extends Controller
{
    public function index(): JsonResponse
    {
        $stores = Store::active()->get();

        return $this->successResponse($stores, 'Stores fetched successfully.');
    }
}
