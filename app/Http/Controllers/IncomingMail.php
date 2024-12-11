<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
        if ($to != "7fe28d61bfe8871aa4ce@cloudmailin.net"){
            return response("Destination address not expected", 422)
                ->header('content-type', 'text/plain');
        }
        $subject = $request->input('headers.subject');
        if (strpos($subject, "Alertas y Notificaciones") === false){
            return response("Subject is not expected: " . $subject, 422)
                ->header('content-type', 'text/plain');
        }
        $plain = $request->input('plain');
        $valid_lines = [];
        $valid_lines[] = "Bancolombia le informa";
        $valid_lines[] = "Bancolombia: Pagaste";
        $valid_lines[] = "Bancolombia le informa Compra por";
        $valid_lines[] = "Bancolombia: Transferiste";
        $valid_lines[] = "Bancolombia: Compraste";
        $valid_lines[] = "Bancolombia te informa Pago por";
        
        $lines = explode("\n", $plain);
        $joined = [];
        $newline = "";
        foreach ($lines as $line){
            $line = trim($line);
            if (strlen($line) == 0){
                if (strlen($newline) > 0){
                    $newline = preg_replace("/\[image: [^\]]+\]/", "", $newline);
                    $newline = preg_replace("/<[^>]+>/", "", $newline);
                    $newline = trim($newline);
                    foreach ($valid_lines as $valid_line){
                        if (strpos($newline, $valid_line) !== false){
                            $response = $this->chat($newline);
                            $joined[] = trim($newline);
                            $joined[] = trim(json_encode($response));
                            break;
                        }
                    }
                    $newline = "";
                }
            } else {
                $newline .= " " . $line;
            }
        }
        if (count($joined) == 0) {
            Log::info("No valid lines found: " . $plain);
            return response("No valid lines found: " . $plain, 422)
                ->header('content-type', 'text/plain');
        }
        $plain = implode("\n", $joined);
        Log::info("Plain content: (" . count($joined) . ") " . $plain);
        
        return response($plain, 200)
            ->header('content-type', 'text/plain');
    }
    
    public function chat($message)
    {
        $openAIEndpoint = 'https://api.openai.com/v1/chat/completions';
        $openai_key = env('OPENAI_KEY');
        $response = Http::withToken($openai_key)
            // ->withHeader('Content-Type', 'application/json')
            ->post($openAIEndpoint, [
                "model" => "gpt-4o-mini",
                "messages" => [
                    [
                        "role" => "user", 
                        "content" => "For the following text, extract the information of date, payee, account number if available, boolean indicating if it was a credit card, comment with the type of transaction, and value in a json object. value should be without currency symbol, decimal point, or thousands separator. For example: {\"date\": \"2022-01-01\", \"payee\": \"John Doe\", \"value\": \"275171\", \"comment\": \"Payment\", \"credit_card\": false, \"account_number\": \"123456\"}"
                    ],
                    [
                        "role" => "user", 
                        "content" => $message
                    ]
                ],
                'response_format' => ['type' => 'json_object'],
            ]);
        $chatResponse = $response->json()['choices'][0]['message']['content'] ?? "No response";
        $responseArray = json_decode($chatResponse, true);
        return $responseArray;
    }
}
