<?php
class IADeepSeek {
    private $api_key;
    private $url = "https://openrouter.ai/api/v1/chat/completions";

    public function __construct($api_key) {
        $this->api_key = trim($api_key);
    }

    public function interpretar($razon, $valor, $anterior = null, $contexto = "") {
        $tendencia = $anterior !== null 
            ? ($valor > $anterior ? "mejoró" : "empeoró") . " de " . round($anterior, 2) . " a " . round($valor, 2)
            : "es " . round($valor, 2);

        $prompt = "Eres un analista financiero experto en empresas comerciales. 
        Explica qué significa que la $razon $tendencia. 
        Incluye impacto, riesgos y 2-3 recomendaciones prácticas. 
        Contexto: $contexto. 
        Responde en español, máximo 3 párrafos, claro y profesional.";

        $data = [
            "model" => "deepseek/deepseek-chat",
            "messages" => [
                ["role" => "user", "content" => $prompt]
            ],
            "temperature" => 0.7,
            "max_tokens" => 300
        ];

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . $this->api_key,
                "Content-Type: application/json",
                "HTTP-Referer: http://localhost/financom",
                "X-Title: FinanCom"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // Errores claros
        if ($curl_error) {
            return "Error de conexión: $curl_error";
        }
        if ($http_code !== 200) {
            $error_msg = json_decode($response, true)['error']['message'] ?? $response;
            return "Error API: $http_code - $error_msg";
        }

        $result = json_decode($response, true);
        return $result['choices'][0]['message']['content'] ?? "Sin respuesta de IA";
    }
}
?>