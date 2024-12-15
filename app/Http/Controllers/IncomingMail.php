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
        $from = $request->input('envelope.from');
        if ($to != "7fe28d61bfe8871aa4ce@cloudmailin.net"){
            return response("Destination address not expected:" . $to, 422)
                ->header('content-type', 'text/plain');
        }
        if (strpos($from, "cloudmailin.net")){
          return response("From address not expected: " . $from, 422)
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
                            $this->addTransaction($response);
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
            Log::info("From: " . $from . ', To: ' . $to );
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
                        "content" => "For the following text in Spanish, extract the information of date, payee, account number if available, boolean indicating if it was a credit card, 
                        comment with the type of transaction, and value in a json object. value should be without currency symbol, decimal point, or thousands separator. Values are in COP$.
                        For example: {\"date\": \"2022-01-01\", \"payee\": \"John Doe\", \"value\": \"275171\", \"memo\": \"Payment\", \"credit_card\": false, \"account_number\": \"123456\"}"
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
    
    public function addTransaction($data) {
        $token = env('YNAB_TOKEN');
        $budget_id = env('YNAB_BUDGET_ID');
        $response = Http::withToken($token)->post('https://api.ynab.com/v1/budgets/' . $budget_id . '/transactions', [
            "transaction" => [
                "account_id" => '7eaabf30-c98d-40ae-9e37-b5cfa1688f27',
                "date" => date('Y-m-d', strtotime($data['date'])),
                "amount" => intval($data['value'])*-1000,
                "payee_id" => NULL,
                "payee_name" => $data['payee'],
                "category_id" => NULL,
                "memo" => $data['memo'],
                "cleared" => "uncleared",
                "approved" => false,
                "flag_color" => NULL,
                "import_id" => NULL
            ]
        ]);
        return $response['data']['transaction'];
    }
}
