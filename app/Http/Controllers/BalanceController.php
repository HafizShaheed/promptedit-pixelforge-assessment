<?php

namespace App\Http\Controllers;

use App\Services\PixelforgeClient;
use Inertia\Inertia;
use Inertia\Response;

class BalanceController extends Controller
{
    /** Balance page — fetched fresh from Pixelforge on every load. */
    public function index(PixelforgeClient $client): Response
    {
        $balance = null;
        $error = null;

        try {
            $response = $client->getBalance();

            if ($response->successful()) {
                $balance = $response->json();
            } else {
                $error = 'Pixelforge returned an error (HTTP '.$response->status().') while loading the balance.';
            }
        } catch (\Throwable) {
            $error = 'Pixelforge could not be reached, so the live balance is unavailable right now. Please try again shortly.';
        }

        return Inertia::render('Balance/Index', [
            'balance' => $balance,
            'error' => $error,
        ]);
    }
}
