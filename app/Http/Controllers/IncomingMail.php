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
        $to = $request->input('headers.to');
        $from = $request->input('headers.from');
        $valid_emails = [
            "jcorrego@gmail.com",
            "jhnbarreto@gmail.com"
        ];
        if (!in_array($to, $valid_emails)){
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
        $message = $request->input('html');
        $message = strip_tags($message);
        $valid_lines = [];
        $valid_lines[] = "Bancolombia le informa";
        $valid_lines[] = "Bancolombia: Pagaste";
        $valid_lines[] = "Bancolombia le informa Compra por";
        $valid_lines[] = "Bancolombia: Transferiste";
        $valid_lines[] = "Bancolombia: Compraste";
        $valid_lines[] = "Bancolombia te informa Pago por";
        $valid_lines[] = "Bancolombia informa consignacion";
        $valid_lines[] = "Bancolombia: Recibiste una transferencia";
        
        foreach ($valid_lines as $valid_line){
            if (($pos = strpos($message, $valid_line)) !== false){
                $message = substr($message, $pos);
                if (($pos = strpos($message, "Llamanos")) !== false) {
                    $message = substr($message, 0, $pos);
                }
                if (($pos = strpos($message, "Inquietudes al")) !== false) {
                  $message = substr($message, 0, $pos);
              }
              
            }
        }
        $response = $this->chat($message);
        
        if (empty($response)){
            return response("No valid response.", 422)
                ->header('content-type', 'text/plain');
        }
        
        $response = $this->setAccountId($response, $message);
        $this->addTransaction($response);
        $joined = [];
        $joined[] = trim(json_encode($response));
        Log::info("Plain content: (" . count($joined) . ") " . $message);
        Log::info($joined);
        
        return response($message, 200)
            ->header('content-type', 'text/plain');
    }
    
    public function chat($message){
        $openAIEndpoint = 'https://api.openai.com/v1/chat/completions';
        $openai_key = env('OPENAI_KEY');
        $response = Http::withToken($openai_key)
            // ->withHeader('Content-Type', 'application/json')
            ->post($openAIEndpoint, [
                "model" => "gpt-4o-mini",
                "messages" => [
                    [
                        "role" => "user", 
                        "content" => "For the following text in Spanish, extract the information of date, payee, account number if available (or use credit card final numbers), boolean indicating if it was a credit card, 
                        comment with the type of transaction, and value in a json object. value should be without currency symbol, decimal point, or thousands separator. Values are in COP$.
                        For example: {\"date\": \"2022-01-01\", \"payee\": \"John Doe\", \"value\": \"275171\", \"memo\": \"Payment\", \"credit_card\": false, \"account\": \"123456\"}"
                    ],
                    [
                        "role" => "user", 
                        "content" => $message
                    ]
                ],
                'response_format' => ['type' => 'json_object'],
            ]);
        $chatResponse = $response->json()['choices'][0]['message']['content'] ?? "No response";
        $responseArray = json_decode($chatResponse, TRUE);
        return $responseArray;
    }

    /**
     * Get the corresponding account id
     *
     * @param array $data
     * @return array
     */
    public function setAccountId($data, $message) {
        $budget_id = env('YNAB_BUDGET_ID');
        $data['budget'] = $budget_id;
        $data['value'] = intval($data['value']) * -1000;
        if (str_ends_with($data['account'], '8821') || (strpos($data['payee'], 'BARRIO LIJACA II BOGOTA') !== FALSE)) {
          // Cta Sucesion.
          $data['account'] = '5f0da59b-7c8b-4276-8119-43e9a3fd6e56';
          $data['budget'] = '039d8b03-ecb2-48ec-8258-c309ac93a594';
        } elseif (str_ends_with($data['account'], '9681') && $data['credit_card'] == TRUE) {
          // Visa Bancolombia JHN
          $data['account'] = 'f45eef68-2634-42f3-bcac-a713a8dcf625';
        } elseif (str_ends_with($data['account'], '3772') && $data['credit_card'] == TRUE) {
          // Visa Bancolombia JCO
          $data['account'] = 'ec0b484d-70df-44c7-977c-46baeb526233';
        } elseif (str_ends_with($data['account'], '4928') || str_ends_with($data['account'], '7225')) {
          // Cta Ahorros Bancolombia JHN
          $data['account'] = 'cace135f-e574-4ed3-a1c7-79feebe13a4e';
        } elseif (str_ends_with($data['account'], '1248') || str_ends_with($data['account'], '5249')) {
          // Cta Ahorros Bancolombia JCO
          $data['account'] = '620e7ce6-6a72-4fc2-83b2-e692109d1b87';
        } elseif (str_ends_with($data['account'], '3955')) {
          // Fiducuenta JC
          $data['account'] = '88599a1a-b5d8-4e9c-9871-13d39d7e41b2';
        }
        else {
          // Efectivo JCO
          $data['memo'] .= " Account: " . $data['account'];
          $data['account'] = '7eaabf30-c98d-40ae-9e37-b5cfa1688f27';
        }

        if(strpos($data['payee'], '3045814372') !== FALSE) {
          $data['payee'] = 'Sarita';
        } elseif (strpos($data['payee'], '3134776191') !== FALSE) {
          $data['payee'] = 'Veterinaria';
        } elseif (strpos($data['payee'], '3142739861') !== FALSE) {
          $data['payee'] = 'Servicios Publicos';
        } elseif (strpos($data['payee'], '3103175608') !== FALSE) {
          $data['payee'] = 'Hogar Los Robles';
        } elseif (strpos($data['payee'], 'Claro Colombia') !== FALSE) {
          $data['payee'] = 'Claro';
        } elseif (strpos($data['payee'], 'TIE CAF JUAN VAL CLI') !== FALSE) {
          $data['payee'] = 'Juan Valdez';
          $data['memo'] = 'Juan Valdez Clinica Marly';
        } elseif (strpos($data['payee'], 'PARKING INTERNATIONA') !== FALSE) {
          $data['payee'] = 'Parqueadero';
          $data['memo'] = 'Parking International';
        } elseif (strpos($data['payee'], 'PRQUEAD 51 CLINICA M') !== FALSE) {
          $data['payee'] = 'Parqueadero';
          $data['memo'] = 'Parqueadero Clinica Marly';
        } elseif (strpos($data['payee'], 'GoPass Pagos Aut') !== FALSE) {
          $data['payee'] = 'GoPass';
          $data['memo'] = 'GoPass Pagos Automaticos';
        } elseif (strpos($data['payee'], 'DESARROLLADORA CC FO') !== FALSE) {
          $data['payee'] = 'Parqueadero';
          $data['memo'] = 'Parqueadero Centro Comercial Fontanar';
        } elseif (stripos($data['payee'], 'NETFLIX') !== FALSE) {
          $data['payee'] = 'Netflix';
          $data['memo'] = 'Subscripcion mensual';
        } elseif (stripos($data['payee'], 'CINEPOLIS FONTANAR') !== FALSE) {
          $data['payee'] = 'Cinepolis';
          $data['memo'] = 'Cinepolis Fontanar';
        } elseif (stripos($data['payee'], 'TIENDA ADIDAS') !== FALSE) {
          $data['payee'] = 'Adidas';
          $data['memo'] = 'Tienda Adidas Fontanar';
        } elseif (stripos($data['payee'], 'AMERICAN EAGLE') !== FALSE) {
          $data['payee'] = 'American Eagle';
          $data['memo'] = 'AMERICAN EAGLE OUTFITTERS';
        } elseif (stripos($data['payee'], 'EL GALAPAGO CAMPESTR') !== FALSE) {
          $data['payee'] = 'El Galapago Campestre';
        } elseif (stripos($data['payee'], 'UBER RIDES') !== FALSE) {
          $data['payee'] = 'Uber';
        }
        
        
        if (strpos($message, 'Bancolombia informa consignacion') !== FALSE){
          $data['value'] *= -1;
          $data['memo'] = 'Consignacion';
        }
        if (strpos($message, 'Bancolombia: Recibiste una transferencia') !== FALSE){
          $data['value'] *= -1;
          $data['memo'] = 'Transferencia';
        }
        
        return $data;
    }
    /**
     * Add transaction to YNAB
     *
     * @param array $data
     * @return void
     */
    public function addTransaction($data) {
        $token = env('YNAB_TOKEN');
        $response = Http::withToken($token)->post('https://api.ynab.com/v1/budgets/' . $data['budget'] . '/transactions', [
            "transaction" => [
                "account_id" => $data['account'],
                "date" => date('Y-m-d', strtotime($data['date'])),
                "amount" => $data['value'],
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
