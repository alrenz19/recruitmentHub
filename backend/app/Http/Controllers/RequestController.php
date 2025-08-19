<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class RequestController extends Controller
{
    // Handles POST /submit
    public function submit(Request $request)
    {
        $data = $request->all(); // get JSON or form data

        return response()->json([
            'message' => 'Data received successfully',
            'data' => $data
        ]);
    }

    // Handles GET /data
    public function getData()
    {
        return response()->json([
            'message' => 'Here is your data',
            'data' => [
                'id' => 1,
                'name' => 'John Doe'
            ]
        ]);
    }
}
