<?php
// ajax/periodos.php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']['id_usuario'])) {
    // devolver array vacío para mantener compatibilidad con JS actual
    echo json_encode([]);
    exit;
}

$id_usuario = $_SESSION['user']['id_usuario'];
$id_empresa = isset($_GET['empresa']) ? intval($_GET['empresa']) : 0;

if (!$id_empresa) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id_periodo, nombre_periodo 
        FROM periodos 
        WHERE id_empresa = ? 
        ORDER BY fecha_inicio DESC
    ");
    $stmt->execute([$id_empresa]);
    $periodos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($periodos);
} catch (Exception $e) {
    // en caso de error DB devolvemos vacío (puedes loguear aquí)
    error_log("Error ajax/periodos.php: " . $e->getMessage());
    echo json_encode([]);
}
?>
