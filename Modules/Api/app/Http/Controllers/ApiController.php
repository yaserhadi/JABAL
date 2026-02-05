<?php

namespace Modules\Api\Http\Controllers;

use Illuminate\Http\Request;

class ApiController extends BaseApiController
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return $this->success(['message' => 'API v1']);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        return $this->success([], 201);
    }

    /**
     * Show the specified resource.
     */
    public function show(string $id)
    {
        return $this->success(['id' => $id]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        return $this->success(['id' => $id]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        return response()->noContent(204);
    }
}
