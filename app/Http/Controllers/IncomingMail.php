<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IncomingMail extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        $to = $request->input('envelope.to');
        Log::info($to);

        if ($to != "7fe28d61bfe8871aa4ce@cloudmailin.net"){
            return response("Destination address not expected", 422)
                ->header('content-type', 'text/plain');
        }

        $file = $request->file('attachments')[0];
        Log::info($file);

        return "Thanks!";
    }
}
