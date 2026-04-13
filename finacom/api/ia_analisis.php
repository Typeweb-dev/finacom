<?php
session_start();
header("Content-Type: application/json; charset=utf-8");

// =========================
//   VALIDAR SESIÓN
// =========================
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Sesión no iniciada"]);
    exit;
}

// =========================
//   CLAVE Y MODELO
// =========================
$API_KEY = "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";
$API_URL = "https://openrouter.ai/api/v1/chat/completions";
$MODEL   = "gpt-4.1-mini";

// =========================
//   LEER PAYLOAD
// =========================
$input = json_decode(file_get_contents("php://input"), true);
$payload = $input["payload"] ?? null;

if (!$payload) {
    echo json_encode(["success" => false, "error" => "Payload inválido"]);
    exit;
}

// =========================
//   REDUCIR ANALISIS HORIZONTAL (TOP 30)
// =========================
function topCambios($lista, $limite = 30) {
    foreach ($lista as &$r) {
        $a = isset($r["valor_anterior"]) ? floatval($r["valor_anterior"]) :
             ((isset($r["saldo_anterior_balance"]) ? floatval($r["saldo_anterior_balance"]) : 0) +
              (isset($r["saldo_anterior_resultado"]) ? floatval($r["saldo_anterior_resultado"]) : 0));

        $b = isset($r["valor_actual"]) ? floatval($r["valor_actual"]) :
             ((isset($r["saldo_actual_balance"]) ? floatval($r["saldo_actual_balance"]) : 0) +
              (isset($r["saldo_actual_resultado"]) ? floatval($r["saldo_actual_resultado"]) : 0));

        $r["_abs"] = abs($b - $a);
    }

    usort($lista, fn($x, $y) => $y["_abs"] <=> $x["_abs"]);
    $out = array_slice($lista, 0, $limite);

    foreach ($out as &$r) unset($r["_abs"]);
    return $out;
}

$payload["analisisHorizontal_top"] = topCambios($payload["analisisHorizontal"] ?? [], 30);

// =========================
//   PROMPT (MÁS LARGO Y ABARCADOR)
// =========================
$prompt = "
Eres un analista financiero senior con amplia experiencia en diagnóstico empresarial.

Genera un ANÁLISIS FINANCIERO sólido, claro y estructurado. Devuelve **únicamente JSON válido**.

Cada sección debe ser un **array** de frases completas (30–50 palabras).  
Cada array debe seguir este formato:

1) Primer elemento → 'Lo más importante:' con la idea clave (30–50 palabras).  
2) Segundo elemento → 'Conclusión:' con síntesis estratégica (30–50 palabras).  
3) Siguientes elementos (máx 3) → 'Recomendación:' con acciones concretas (30–50 palabras).  

### EXCEPCIÓN IMPORTANTE: RAZONES FINANCIERAS

En la sección **\"razones\"**, debes generar un array donde **cada elemento describe brevemente la interpretación de una razón financiera específica**, usando **20–35 palabras** cada una.

Formato obligatorio para razones:

- Cada elemento debe iniciar con el nombre de la razón, por ejemplo:  
  \"Razón corriente: ...\"  
  \"Margen neto: ...\"  
  \"Rotación de activos: ...\"

- Interpreta **únicamente las razones presentes** en el payload.  
- NO inventes razones que no existan.  
- NO repitas valores numéricos, interpreta el comportamiento y su impacto.  
- Cada interpretación debe ser de **una sola frase de 20–35 palabras**.

### Cobertura requerida del análisis general (no razones):
Liquidez, gestión de capital de trabajo, estructura de activos, eficiencia operativa, rentabilidad, apalancamiento y solvencia, ciclo de conversión de efectivo, calidad de ingresos, variaciones relevantes, inversión en activos, EOAF y sus implicaciones, flujo de efectivo por actividad, y desglose DuPont explicando cambios en ROE.

### Estructura obligatoria:
{
  \"summary\": [],
  \"horizontal\": [],
  \"vertical\": [],
  \"razones\": [],        ← aquí va la interpretación individual por razón
  \"dupont\": [],
  \"eoaf\": [],
  \"flujo\": [],
  \"recommendations\": []
}

Reglas estrictas:
- No incluyas texto fuera del JSON.
- Nada de markdown, comillas triples ni etiquetas HTML.
- No copies datos numéricos; interpreta.
- Cada frase debe ser clara, profesional y concreta.
- Máximo 5 elementos por array excepto **razones**, que admite tantos como razones existan.

Datos:
" . json_encode($payload, JSON_UNESCAPED_UNICODE);


// =========================
//   REQUEST AL MODELO
// =========================
$body = [
    "model" => $MODEL,
    "messages" => [
        ["role" => "system", "content" => "Eres un analista financiero experto."],
        ["role" => "user", "content" => $prompt]
    ],
    "max_tokens" => 2000,
    "temperature" => 0.12
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $API_URL);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer $API_KEY"
]);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $http >= 400) {
    echo json_encode([
        "success" => false,
        "error" => "Error al conectar con IA",
        "http" => $http,
        "raw" => $response
    ]);
    exit;
}

// =========================
//   EXTRAER Y LIMPIAR CONTENIDO DEL MODELO
// =========================
$json = json_decode($response, true);
$content = $json["choices"][0]["message"]["content"] ?? null;

if (!$content) {
    echo json_encode([
        "success" => false,
        "error" => "La IA no devolvió contenido",
        "raw" => $response
    ]);
    exit;
}

// 1) eliminar fences de Markdown (```json, ```), y etiquetas HTML como <p>, <br>, etc.
$content_clean = preg_replace('/```(?:json|[a-z]*)\s*/i', '', $content);
$content_clean = str_replace('```', '', $content_clean);
$content_clean = strip_tags($content_clean);

// 2) intentar extraer el primer objeto JSON entre llaves { ... }
$extracted = null;
if (preg_match('/(\{[\s\S]*\})/', $content_clean, $m)) {
    $extracted = $m[1];
} else {
    // si no logra extraer, usar el texto limpio
    $extracted = trim($content_clean);
}

// 3) intentar decodificar JSON
$analysis = null;
if ($extracted) {
    $analysis = json_decode($extracted, true);
}

// =========================
//   RESPUESTA
// =========================
if (is_array($analysis)) {
    // devolver objeto JSON ya parseado
    echo json_encode([
        "success" => true,
        "analysis" => $analysis
    ]);
    exit;
} else {
    // fallback: enviar texto limpio en analysis_raw (sin marcas)
    // limitamos tamaño para evitar payloads gigantes
    $safe_text = mb_substr($extracted, 0, 20000);
    echo json_encode([
        "success" => true,
        "analysis_raw" => $safe_text
    ]);
    exit;
}
?>
