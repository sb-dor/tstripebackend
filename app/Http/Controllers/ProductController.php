<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Return all products.
     *
     * GET /api/products
     */
    public function index(): \Illuminate\Http\JsonResponse
    {
        return response()->json(\App\Models\Product::all());
    }
}
