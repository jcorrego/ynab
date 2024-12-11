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
                            $joined[] = $newline;
                            $joined[] = $response;
                            break;
                        }
                    }
                    $newline = "";
                }
            } else {
                $newline .= " " . $line;
            }
        }
        $plain = implode("\n", $joined);
        
        return response($plain, 200)
            ->header('content-type', 'text/plain');
    }
    
    public function chat($message)
    {
        $openAIEndpoint = 'https://api.openai.com/v1/chat/completions';
        $openai_key = env('OPENAI_KEY');
        $response = Http::withToken($openai_key)->post($openAIEndpoint, [
            "model" => "gpt-4o-mini",
            "messages" => [
                [
                    "role" => "user", 
                    "content" => "For the following text, extract the information of date, payee, account number if available, comment with the type of transaction, and value in a json object: " . $message
                ]
            ],
            'temperature' => 0.7,
        ]);
        $chatResponse = $response->json()['choices'][0]['message']['content'] ?? "No response";
        // Convert JSON string to array
        $chatResponse = str_replace("json", '', $chatResponse);
        $chatResponse = str_replace("```", '', $chatResponse);
        $responseArray = json_decode($chatResponse, true);
    
        var_dump($responseArray);
        return response()->json([
            'response' => $chatResponse,
        ]);
        // return $response->getBody()->getContents();
    }
}
