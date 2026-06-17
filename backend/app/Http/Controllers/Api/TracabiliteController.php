<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class TracabiliteController extends Controller
{
    public function show(string $code_tracabilite)
    {
        return response()->json(['message' => 'À implémenter — module transversal']);
    }
}
