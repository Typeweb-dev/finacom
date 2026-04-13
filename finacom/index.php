<?php
session_start();
require_once 'config/db.php';

// === EVITAR BUCLE DE REDIRECCIÓN ===
if (isset($_GET['redirected']) && $_GET['redirected'] === 'true') {
    $error = "Por favor, inicia sesión para acceder al sistema.";
}

// === PROCESAR LOGIN ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        try {
            $stmt = $pdo->prepare("
                SELECT id_usuario, nombre, email, password_hash, rol 
                FROM usuarios 
                WHERE email = ? AND activo = 1
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // === INICIAR SESIÓN CORRECTAMENTE ===
                $_SESSION['user'] = [
                    'id_usuario' => $user['id_usuario'],
                    'nombre'     => $user['nombre'],
                    'email'      => $user['email'],
                    'rol'        => $user['rol']
                ];
                header("Location: dashboard.php");
                exit;
            } else {
                $error = "Credenciales incorrectas. Intenta de nuevo.";
            }
        } catch (Exception $e) {
            $error = "Error del sistema. Intenta más tarde.";
        }
    } else {
        $error = "Completa todos los campos.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FinanCom - Iniciar Sesión</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); }
        .login-card { border: none; border-radius: 1rem; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .btn-primary { background: #6a1b9a; border: none; border-radius: 0.5rem; padding: 0.75rem; font-weight: 600; }
        .btn-primary:hover { background: #4a148c; }
        .form-control { border-radius: 0.5rem; padding: 0.75rem; }
        .logo { width: 60px; height: 60px; background: #6a1b9a; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; }
        .logo i { color: white; font-size: 1.8rem; }
    </style>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center min-vh-100">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card login-card">
                <div class="card-body p-5 text-center">
                    <div class="logo">
                        <i class="bi bi-pie-chart-fill"></i>
                    </div>
                    <h3 class="mb-2 fw-bold text-dark">FinanCom</h3>
                    <p class="text-muted mb-4">Análisis financiero inteligente</p>

                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger small"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" class="mt-3">
                        <div class="mb-3">
                            <input 
                                type="email" 
                                name="email" 
                                class="form-control" 
                                placeholder="Correo electrónico" 
                                value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : 'admin@financom.com' ?>" 
                                required
                            >
                        </div>
                        <div class="mb-3">
                            <input 
                                type="password" 
                                name="password" 
                                class="form-control" 
                                placeholder="Contraseña" 
                                required
                            >
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            Iniciar Sesión
                        </button>
                    </form>

                    <div class="mt-4 text-muted small">
                        <strong>Credenciales de prueba:</strong><br>
                        <code>admin@financom.com</code> | <code>123</code>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>