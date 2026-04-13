<?php
require_once 'clases/IADeepSeek.php';

// === PEGA TU CLAVE REAL AQUÍ ===
$API_KEY = "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"; // ← TU CLAVE

$ia = new IADeepSeek($API_KEY);

$interpretacion = $ia->interpretar(
    "Liquidez Corriente",
    2.1,
    1.8,
    "Farmacia Central, Managua. Ventas diarias de C$50,000."
);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>FinanCom - IA Financiera</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="card shadow">
        <div class="card-header bg-success text-white text-center">
            <h3>Interpretación con IA</h3>
        </div>
        <div class="card-body">
            <div class="alert alert-light border">
                <pre class="mb-0"><?= htmlspecialchars($interpretacion) ?></pre>
            </div>
            <small class="text-success">DeepSeek Chat vía OpenRouter • GRATIS</small>
        </div>
    </div>
</div>
</body>
</html>