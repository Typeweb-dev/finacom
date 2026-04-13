<?php
// clases/Analizador.php
// Clase Analizador adaptada al esquema SQL proporcionado (financom1)

class Analizador {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /* ============================
       UTILIDADES GENERALES
       ============================ */

    // devuelve id_periodo anterior para la misma empresa (o null)
    public function getPeriodoAnterior(int $id_periodo): ?int {
        $stmt = $this->pdo->prepare("SELECT id_empresa FROM periodos WHERE id_periodo = ?");
        $stmt->execute([$id_periodo]);
        $id_empresa = $stmt->fetchColumn();
        if (!$id_empresa) return null;

        $stmt = $this->pdo->prepare("SELECT id_periodo FROM periodos WHERE id_empresa = ? AND id_periodo < ? ORDER BY id_periodo DESC LIMIT 1");
        $stmt->execute([$id_empresa, $id_periodo]);
        $res = $stmt->fetchColumn();
        return $res ?: null;
    }

    // suma de saldos en balance_general filtrando por condición sobre cuentas
    private function sumBalanceWhere(int $id_periodo, string $whereCuenta, array $params = []): float {
        $sql = "SELECT COALESCE(SUM(bg.saldo),0) FROM balance_general bg JOIN cuentas_contables c ON bg.id_cuenta = c.id_cuenta WHERE bg.id_periodo = ? AND ({$whereCuenta})";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$id_periodo], $params));
        return (float) $stmt->fetchColumn();
    }

    // suma de montos en estado_resultados filtrando por condición sobre cuentas
    private function sumERWhere(int $id_periodo, string $whereCuenta, array $params = []): float {
        $sql = "SELECT COALESCE(SUM(er.monto),0) FROM estado_resultados er JOIN cuentas_contables c ON er.id_cuenta = c.id_cuenta WHERE er.id_periodo = ? AND ({$whereCuenta})";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$id_periodo], $params));
        return (float) $stmt->fetchColumn();
    }

    // suma por prefijo de codigo_cuenta (p.e. '4' ventas, '5' costos, '6' gastos)
    private function sumERPorPrefijo(int $id_periodo, $prefix): float {
        if (is_array($prefix)) {
            $conds = [];
            $params = [];
            foreach ($prefix as $p) {
                $conds[] = "c.codigo_cuenta LIKE ?";
                $params[] = rtrim($p, '%') . '%';
            }
            $where = implode(' OR ', $conds);
            return $this->sumERWhere($id_periodo, $where, $params);
        } else {
            return $this->sumERWhere($id_periodo, "c.codigo_cuenta LIKE ?", [rtrim($prefix, '%') . '%']);
        }
    }

    /* ============================
       TOTALES (uso por otras funciones)
       ============================ */

    public function getTotalActivoCorriente(int $id_periodo): float {
        return $this->sumBalanceWhere($id_periodo, "c.tipo_cuenta = 'activo_corriente'");
    }
    public function getTotalActivoNoCorriente(int $id_periodo): float {
        return $this->sumBalanceWhere($id_periodo, "c.tipo_cuenta = 'activo_no_corriente'");
    }
    public function getTotalActivo(int $id_periodo): float {
        return $this->getTotalActivoCorriente($id_periodo) + $this->getTotalActivoNoCorriente($id_periodo);
    }
    public function getTotalPasivoCorriente(int $id_periodo): float {
        return $this->sumBalanceWhere($id_periodo, "c.tipo_cuenta = 'pasivo_corriente'");
    }
    public function getTotalPasivoNoCorriente(int $id_periodo): float {
        return $this->sumBalanceWhere($id_periodo, "c.tipo_cuenta = 'pasivo_no_corriente'");
    }
    public function getTotalPasivo(int $id_periodo): float {
        return $this->getTotalPasivoCorriente($id_periodo) + $this->getTotalPasivoNoCorriente($id_periodo);
    }
    public function getTotalPatrimonio(int $id_periodo): float {
        return $this->sumBalanceWhere($id_periodo, "c.tipo_cuenta = 'patrimonio'");
    }

    /* ============================
       PROMEDIOS (promedio entre periodo actual y anterior)
       ============================ */

    // promedio de saldos para tipos de cuenta (p.e. ['activo_corriente','activo_no_corriente'])
    public function getPromedioBalance(int $id_periodo, array $tipos): float {
        $prev = $this->getPeriodoAnterior($id_periodo);
        $sum_actual = 0.0;
        $sum_prev = 0.0;
        foreach ($tipos as $t) {
            $sum_actual += $this->sumBalanceWhere($id_periodo, "c.tipo_cuenta = ?", [$t]);
            if ($prev) $sum_prev += $this->sumBalanceWhere($prev, "c.tipo_cuenta = ?", [$t]);
        }
        if (!$prev) return $sum_actual; // sin anterior devolvemos el actual (evita dividir por 0)
        return ($sum_actual + $sum_prev) / 2;
    }

    // promedio por nombre LIKE (ej: '%Clientes%', '%Mercancías%')
    public function getPromedioBalancePorNombreLike(int $id_periodo, array $nombresLike): float {
    $prev = $this->getPeriodoAnterior($id_periodo);

    // normalizamos los patrones (ya vienen con % si corresponde)
    $likes = array_values($nombresLike);
    if (count($likes) === 0) return 0.0;

    // Si no hay periodo anterior, promediamos solo el periodo actual
    if (!$prev) {
        $conds = implode(' OR ', array_fill(0, count($likes), "c.nombre_cuenta LIKE ?"));
        $sql = "SELECT COALESCE(AVG(bg.saldo),0) FROM balance_general bg
                JOIN cuentas_contables c ON bg.id_cuenta = c.id_cuenta
                WHERE bg.id_periodo = ? AND ({$conds})";
        $params = array_merge([$id_periodo], $likes);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    }

    // Con periodo anterior: usamos UNION ALL con aliases diferenciados (c y c2)
    $condsA = implode(' OR ', array_fill(0, count($likes), "c.nombre_cuenta LIKE ?"));
    $condsB = implode(' OR ', array_fill(0, count($likes), "c2.nombre_cuenta LIKE ?"));

    $sql = "
        SELECT COALESCE(AVG(t.saldo),0) FROM (
            SELECT bg.saldo
            FROM balance_general bg
            JOIN cuentas_contables c ON bg.id_cuenta = c.id_cuenta
            WHERE bg.id_periodo = ? AND ({$condsA})
            UNION ALL
            SELECT bg2.saldo
            FROM balance_general bg2
            JOIN cuentas_contables c2 ON bg2.id_cuenta = c2.id_cuenta
            WHERE bg2.id_periodo = ? AND ({$condsB})
        ) t
    ";

    // parámetros: periodo actual, likes para primer SELECT, periodo anterior, likes para segundo SELECT
    $params = array_merge([$id_periodo], $likes, [$prev], $likes);

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

    /* ============================
       RAZONES FINANCIERAS (adaptadas al plan de cuentas)
       ============================ */

    // Liquidez corriente
    public function calcularLiquidezCorriente(int $id_periodo): ?float {
        $ac = $this->getTotalActivoCorriente($id_periodo);
        $pc = $this->getTotalPasivoCorriente($id_periodo);
        return $pc > 0.0 ? $ac / $pc : null;
    }
    public function calcularCNT(int $id_periodo): ?float {
        $ac = $this->getTotalActivoCorriente($id_periodo);
        $pc = $this->getTotalPasivoCorriente($id_periodo);
        return $pc > 0.0 ? $ac - $pc : null;
    }

    // Prueba ácida (activo corriente - inventarios) / pasivo corriente
    public function calcularPruebaAcida(int $id_periodo): ?float {
        $ac = $this->getTotalActivoCorriente($id_periodo);
        // inventarios: cuentas con codigo 1.1.03* o nombre 'Mercancías'
        $inv = $this->sumBalanceWhere($id_periodo, "c.codigo_cuenta LIKE '1.1.03%' OR c.nombre_cuenta LIKE '%Mercancías%'");
        $pc = $this->getTotalPasivoCorriente($id_periodo);
        $num = $ac - $inv;
        return $pc > 0.0 ? $num / $pc : null;
    }

    // Cash ratio: caja y bancos / pasivo corriente
    public function calcularCashRatio(int $id_periodo): ?float {
        // Caja y Bancos -> codigo 1.1.01.* (id 18 y detalle 19,20)
        $efectivo = $this->sumBalanceWhere($id_periodo, "c.codigo_cuenta LIKE '1.1.01.%' OR c.nombre_cuenta LIKE '%Caja%' OR c.nombre_cuenta LIKE '%Bancos%'");
        $pc = $this->getTotalPasivoCorriente($id_periodo);
        return $pc > 0.0 ? $efectivo / $pc : null;
    }

    // Capital de trabajo
    public function calcularCapitalTrabajo(int $id_periodo): float {
        return $this->getTotalActivoCorriente($id_periodo) - $this->getTotalPasivoCorriente($id_periodo);
    }

    // Margen bruto = (Ventas netas - Costo de ventas) / Ventas netas
    public function calcularMargenBruto(int $id_periodo): ?float {
        // Ventas Brutas id_cuenta = 41; devoluciones 42; descuentos 43 => Ventas Netas = 41 + 42 + 43 (42 y 43 ya son negativos en tu ER)
        $ventas_brutas = $this->sumERWhere($id_periodo, "c.id_cuenta = 41");
        // Ojo: en tu plan hay cuentas que suman para "ventas netas" (también existe TOTAL_VENTAS_NETAS id 100)
        $ventas_netas = $ventas_brutas + $this->sumERWhere($id_periodo, "c.id_cuenta IN (42,43)");
        // Costo de ventas: registros 44 (Inventario Inicial),45 (Compras Netas),46 (-) Inventario Final -> en total tu ER suma ya reflejado en cuenta 44..46; pero existe la cuenta de COSTO DE VENTAS estructural (14.*)
        // En el volcado hay montos en 44,45,46; también puedes usar prefijo '5' (costos)
        $costo_ventas = $this->sumERPorPrefijo($id_periodo, '5'); // p.e. 5.1.*
        if ($ventas_netas == 0.0) return null;
        return (($ventas_netas - $costo_ventas) / $ventas_netas)*100;
    }

    // Margen operativo (utilidad operativa / ventas)
    public function calcularMargenOperativo(int $id_periodo): ?float {
        $ventas = $this->sumERPorPrefijo($id_periodo, '4'); // prefijo 4.*
        if ($ventas == 0.0) return null;
        $costo_ventas = $this->sumERPorPrefijo($id_periodo, '5');
        // gastos operativos: prefijos 6.1 y 6.2
        $gastos_operativos = $this->sumERPorPrefijo($id_periodo, ['6.1','6.2']);
        $util_operativa = $ventas - $costo_ventas - $gastos_operativos;
        return $util_operativa / $ventas;
    }

    // Margen neto = utilidad neta / ventas netas
    public function calcularMargenNeto(int $id_periodo): ?float {
    // 1. Calcular Ventas Netas (Denominador)
    // Se asume que las Ventas Netas son la suma de todas las cuentas de INGRESOS (prefijo '4'),
    // que ya incluyen las deducciones (Devoluciones, Descuentos) como saldos negativos.
    // También se puede usar el ID 100 'VENTAS NETAS' si estuviera registrado en estado_resultados,
    // pero el enfoque por prefijo '4' cubre los componentes (41, 42, 43).

    $ventas_netas = $this->sumERPorPrefijo($id_periodo, '4');

    if ($ventas_netas == 0.0) {
        // No hay ingresos, el margen no puede calcularse.
        return null;
    }

    // 2. Calcular Utilidad Neta (Numerador)
    // La forma más robusta es sumar los componentes del Estado de Resultados:
    // Utilidad Neta = Ingresos (4) - Costos (5) - Gastos (6) - Impuestos (123)

    $ingresos = $this->sumERPorPrefijo($id_periodo, '4');
    // Las cuentas de Costos (5) y Gastos (6) se registran con valores positivos en ER,
    // por lo que deben restarse del total de Ingresos.
    $costos = $this->sumERPorPrefijo($id_periodo, '5');
    $gastos_operacionales = $this->sumERPorPrefijo($id_periodo, '6');

    // La cuenta de Impuestos (123) se registra con saldo negativo en su volcado,
    // por lo que se SUMA para que la operación sea una RESTA efectiva.
    $impuestos = $this->sumERWhere($id_periodo, "er.id_cuenta = ?", [123]);

    $utilidad_neta = $ingresos - $costos - $gastos_operacionales + $impuestos;

    // 3. Cálculo del Margen Neto
    return ($utilidad_neta / $ventas_netas);
}

    /**
 * Calcula el Rendimiento sobre el Patrimonio (ROE) para un periodo dado.
 *
 * @param int $id_periodo El ID del periodo para el cual se calcula el ROE.
 * @return float|null El valor del ROE (en decimal) o null si no se puede calcular.
 */
public function calcularRendimientoSobrePatrimonio(int $id_periodo): ?float {

    // --- 1. Obtener la Utilidad Neta (del Estado de Resultados) (Numerador) ---
    // La forma más robusta es calcular la Utilidad Neta a partir de los prefijos del ER.
    // Utilidad Neta = Ingresos (4) - Costos (5) - Gastos (6) - Impuestos (123)

    $ingresos = $this->sumERPorPrefijo($id_periodo, '4');
    $costos = $this->sumERPorPrefijo($id_periodo, '5'); // Restar
    $gastos_operacionales = $this->sumERPorPrefijo($id_periodo, '6'); // Restar
    // Cuenta 123 (Impuestos) tiene saldo negativo en el volcado, así que la sumamos para restar efectivamente.
    $impuestos = $this->sumERWhere($id_periodo, "er.id_cuenta = ?", [123]);

    $utilidad_neta = $ingresos - $costos - $gastos_operacionales + $impuestos;

    // Si la Utilidad Neta es cero, el ROE es cero.
    if ($utilidad_neta == 0.0) {
        return 0.0;
    }


    // --- 2. Obtener el Patrimonio Promedio (Denominador) ---
    // Asumimos que la función auxiliar getPromedioBalance($id_periodo, ['patrimonio'])
    // ya recupera el promedio del Patrimonio Total (Cuentas con tipo_cuenta='patrimonio' o prefijo '3')
    // entre el periodo actual y el anterior.
    $patrimonio_promedio = $this->getTotalPatrimonio($id_periodo);
    
    // Validar para evitar la división por cero.
    if ($patrimonio_promedio == 0.0) {
        return null; // No se puede calcular si no hay patrimonio promedio.
    }

    // --- 3. Calcular el ROE ---
    return ($utilidad_neta / $patrimonio_promedio)*100 ; // Retorna en formato decimal (e.g., 0.15)
}

    // Rotación de activos = ventas / promedio de activos
    public function calcularRotacionActivos(int $id_periodo): ?float {
        $ventas = $this->sumERPorPrefijo($id_periodo, '4');
        $activo_promedio = $this->getPromedioBalance($id_periodo, ['activo_corriente','activo_no_corriente']);
        return ($activo_promedio > 0.0 && $ventas > 0.0) ? ($ventas / $activo_promedio) : null;
    }

    // Rotación inventarios = costo ventas / inventario promedio
    public function calcularRotacionInventarios(int $id_periodo): ?float {
        $costo_ventas = $this->sumERPorPrefijo($id_periodo, '5');
        $inventario_promedio = $this->getPromedioBalancePorNombreLike($id_periodo, ['%Inventario%','%Mercancías%']);
        return ($inventario_promedio > 0.0) ? ($costo_ventas / $inventario_promedio) : null;
    }

    // Rotación cuentas por cobrar = ventas / cuentas por cobrar promedio
    public function calcularRotacionCuentasPorCobrar(int $id_periodo): ?float {
        $ventas = $this->sumERPorPrefijo($id_periodo, '4');
        $cxc_prom = $this->getPromedioBalancePorNombreLike($id_periodo, ['%Clientes%','%Cuentas por Cobrar%','%Clientes (Cuentas por Cobrar)%']);
        return ($cxc_prom > 0.0 && $ventas > 0.0) ? ($ventas / $cxc_prom) : null;
    }

    // Días ventas por cobrar (360 días)
    public function calcularDiasCartera(int $id_periodo): ?float {
        $rot = $this->calcularRotacionCuentasPorCobrar($id_periodo);
        return ($rot > 0.0) ? (365 / $rot) : null;
    }

    // Cobertura de intereses: EBIT / gastos financieros
    

    // Endeudamiento: pasivo / patrimonio
    public function calcularEndeudamiento(int $id_periodo): ?float {
        $pasivo = $this->getTotalPasivo($id_periodo);
        $patrimonio = $this->getTotalPatrimonio($id_periodo);
        return ($patrimonio > 0.0) ? ($pasivo / $patrimonio) : null;
    }
        public function calcularRazonPasivoCapital(int $id_periodo): ?float {
        $pasivo = $this->getTotalPasivo($id_periodo);
        $patrimonio = $this->getTotalPatrimonio($id_periodo);
        // Pasivo Total / Patrimonio Total
        return ($patrimonio > 0.0) ? ($pasivo / $patrimonio) : null;
    }

    // Solvencia: activos / pasivos
    public function calcularSolvencia(int $id_periodo): ?float {
        $act = $this->getTotalActivo($id_periodo);
        $pas = $this->getTotalPasivo($id_periodo);
        return ($pas > 0.0) ? ($act / $pas) : null;
    }

    /* ============================
       ANALISIS VERTICAL / HORIZONTAL
       ============================ */

    // Vertical: balance (porcentaje sobre total activo)
    public function calcularAnalisisVerticalBalance(int $id_periodo): array {
        $total_activo = $this->getTotalActivo($id_periodo);
        if ($total_activo == 0.0) return [];
        $sql = "SELECT c.codigo_cuenta, c.nombre_cuenta, bg.saldo, ROUND((bg.saldo / ?) * 100,2) AS porcentaje FROM balance_general bg JOIN cuentas_contables c ON bg.id_cuenta = c.id_cuenta WHERE bg.id_periodo = ? ORDER BY c.codigo_cuenta";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$total_activo, $id_periodo]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Vertical: resultados (porcentaje sobre ventas netas)
    public function calcularAnalisisVerticalResultados(int $id_periodo): array {
        // Ventas netas: ventas y devoluciones/descuentos
        $ventas_netas = $this->sumERWhere($id_periodo, "c.id_cuenta = 41") + $this->sumERWhere($id_periodo, "c.id_cuenta IN (42,43)");
        if ($ventas_netas == 0.0) return [];
        $sql = "SELECT c.codigo_cuenta, c.nombre_cuenta, er.monto, ROUND((er.monto / ?) * 100,2) AS porcentaje FROM estado_resultados er JOIN cuentas_contables c ON er.id_cuenta = c.id_cuenta WHERE er.id_periodo = ? ORDER BY c.codigo_cuenta";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$ventas_netas, $id_periodo]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function calcularRotacionActivosTotales(int $id_periodo): ?float {
    // Numerador: Ventas Netas. Se suma todo el prefijo '4'.
    $ventas_netas = $this->sumERPorPrefijo($id_periodo, '4');
    
    if ($ventas_netas == 0.0) return null; // No se puede calcular sin ventas.

    // Denominador: Activos Totales Promedio.
    // Usamos el tipo 'activo' (código '1').
    // Se asume que getPromedioBalance ya existe.
    $activos_promedio = $this->getTotalActivo($id_periodo);

    if ($activos_promedio == 0.0) return null; // Evita división por cero.
    
    return $ventas_netas / $activos_promedio;
}
public function calcularMultiplicadorCapital(int $id_periodo): ?float {
    // Numerador: Activos Totales Promedio.
    $activos_promedio = $this->getTotalActivo($id_periodo);
    
    // Denominador: Patrimonio Total Promedio.
    // Usamos el tipo 'patrimonio' (código '3').
    $patrimonio_promedio = $this->getTotalPatrimonio($id_periodo);
    
    if ($patrimonio_promedio == 0.0) return null; // Evita división por cero.

    return $activos_promedio / $patrimonio_promedio;
}
        /**
     * Calcula el análisis DuPont (3 pasos) para el periodo dado.
     *
     * Devuelve un array con:
     *  - margen_neto: utilidad_neta / ventas (decimal, ej. 0.12) o null
     *  - rotacion_activos: ventas / activo_promedio (decimal) o null
     *  - multiplicador_patrimonial: activo_promedio / patrimonio_promedio o null
     *  - roe_dupont: producto de las 3 componentes (decimal) o null
     *
     * Notas:
     *  - Utilidad neta se calcula como: ingresos(4) - costos(5) - gastos(6) + impuestos(123)
     *  - Promedios de activo y patrimonio usan getPromedioBalance para tomar promedio entre periodos (si existe anterior).
     */
    public function calcularAnalisisDupont(int $id_periodo): ?array {
    
    // --- 1. Calcular los tres componentes (Factores) ---
    
    // 1.1 Margen Neto (Rentabilidad sobre las Ventas)
    // Se reutiliza el método mejorado anteriormente.
    $margen_neto = $this->calcularMargenNeto($id_periodo); 
    
    // 1.2 Rotación de Activos Totales (Eficiencia Operativa)
    $rotacion_activos = $this->calcularRotacionActivosTotales($id_periodo);
    
    // 1.3 Multiplicador del Capital (Apalancamiento Financiero)
    $multiplicador_capital = $this->calcularMultiplicadorCapital($id_periodo);

    // --- 2. Validar y Calcular ROE ---
    
    if (is_null($margen_neto) || is_null($rotacion_activos) || is_null($multiplicador_capital)) {
        // Si falta alguno de los componentes, no se puede realizar el análisis.
        return null; 
    }
    
    // ROE = Margen Neto x Rotación de Activos x Multiplicador del Capital
    $roe_dupont = $margen_neto * $rotacion_activos * $multiplicador_capital;
    
    // --- 3. Retornar el resultado descompuesto ---
    
    return [
        'roe' => $roe_dupont,
        'margen_neto' => $margen_neto,
        'rotacion_activos' => $rotacion_activos,
        'multiplicador_capital' => $multiplicador_capital
    ];
}


    // Horizontal: comparar balance actual vs anterior (si anterior null devuelve [])
    /**
 * Compara cuentas entre dos periodos incluyendo tanto balance_general (saldo)
 * como estado_resultados (monto). Devuelve por cuenta:
 *  - codigo_cuenta, nombre_cuenta
 *  - valor_anterior (balance_anterior + resultado_anterior)
 *  - valor_actual  (balance_actual  + resultado_actual)
 *  - diferencia
 *  - variacion_porcentual (NULL si divisor = 0)
 */
/**
 * Calcula el Flujo de Efectivo por método indirecto para un periodo dado.
 * Devuelve un array con la estructura esperada por la vista:
 * [
 *   'operacion' => [
 *       'utilidad_neta' => float,
 *       'ajustes_no_efectivo' => ['depreciacion'=>..., 'provisiones'=>..., 'otros_no_efectivo'=>..., 'total'=>...],
 *       'cambios_capital_trabajo' => ['delta_cxc'=>..., 'delta_inventario'=>..., 'delta_proveedores'=>..., 'total'=>...],
 *       'flujo_operativo' => float
 *   ],
 *   'inversion' => ['cambio_activo_no_corriente'=>..., 'flujo_inversion'=>...],
 *   'financiamiento' => ['delta_deuda_lt'=>..., 'delta_patrimonio'=>..., 'flujo_financiamiento'=>...],
 *   'net_change_calc' => float,
 *   'saldo_inicial_efectivo' => float,
 *   'saldo_final_efectivo' => float,
 *   'reconciliacion_diff' => float
 * ]
 */
/**
 * Calcula el Estado de Origen y Aplicación de Fondos (método indirecto).
 *
 * Retorna un array con la estructura:
 * [
 *   'operacion' => [
 *       'utilidad_neta' => float,
 *       'ajustes_no_efectivo' => ['depreciacion'=>..., 'provisiones'=>..., 'otros_no_efectivo'=>..., 'total'=>...],
 *       'cambios_capital_trabajo' => ['delta_cxc'=>..., 'delta_inventario'=>..., 'delta_proveedores'=>..., 'total'=>...],
 *       'flujo_operativo' => float
 *   ],
 *   'inversion' => ['cambio_activo_no_corriente'=>..., 'flujo_inversion'=>...],
 *   'financiamiento' => ['delta_deuda_lt'=>..., 'delta_patrimonio'=>..., 'flujo_financiamiento'=>...],
 *   'net_change_calc' => float,
 *   'saldo_inicial_efectivo' => float,
 *   'saldo_final_efectivo' => float,
 *   'reconciliacion_diff' => float
 * ]
 *
 * Nota: si no existe periodo anterior, los deltas se calculan como 0 y se devuelve lo que pueda calcularse.

 * Calcula el EOAF (Estado de Origen y Aplicación) a partir de las VARIACIONES (B - A)
 * entre dos períodos. Clasifica cada variación como Origen o Aplicación según:
 *  - Activo: aumento => Aplicación, disminución => Origen
 *  - Pasivo/Patrimonio: aumento => Origen, disminución => Aplicación
 *
 * @param int $periodoA periodo base
 * @param int $periodoB periodo comparación (actual)
 * @return array Estructura:
 * [
 *   'origenes' => ['partida'=>monto, ...],
 *   'aplicaciones' => ['partida'=>monto, ...],
 *   'total_origen' => float,
 *   'total_aplicacion' => float,
 *   'saldo_inicial_efectivo' => float,
 *   'saldo_final_efectivo' => float,
 *   'diferencia_origen_aplicacion' => float,
 * ]
 * 
 * /**
 * Calcula el Estado de Origen y Aplicación de Fondos (EOAF) entre dos períodos.
 *
 * - $periodo_anterior: periodo base (A). Si no existe, pasarlo como 0 y se considerarán deltas = 0.
 * - $periodo_actual: periodo comparación (B).
 *
 * Devuelve un array con:
 * [
 *   'origenes' => ['partida' => monto, ...],
 *   'aplicaciones' => ['partida' => monto, ...],
 *   'total_origen' => float,
 *   'total_aplicacion' => float,
 *   'detalle' => [
 *       'operacion' => [
 *           'utilidad_neta' => float,
 *           'ajustes_no_efectivo' => ['depreciacion'=>..., 'provisiones'=>..., 'total'=>...],
 *           'cambios_capital_trabajo' => ['delta_cxc'=>..., 'delta_inventario'=>..., 'delta_proveedores'=>..., 'total'=>...],
 *           'flujo_operativo' => float
 *       ],
 *       'inversion' => ['cambio_activo_no_corriente'=>..., 'flujo_inversion'=>...],
 *       'financiamiento' => ['delta_deuda_lt'=>..., 'delta_patrimonio'=>..., 'flujo_financiamiento'=>...]
 *   ],
 *   'saldo_inicial_efectivo' => float,
 *   'saldo_final_efectivo' => float,
 *   'diferencia_origen_aplicacion' => float
 * ]
 *
 * Principio aplicado: cambios en Activos -> aumento = Aplicación, disminución = Origen.
 *                  cambios en Pasivo/Patrimonio -> aumento = Origen, disminución = Aplicación.
 */
/**
 * Calcula el Estado de Origen y Aplicación de Fondos (EOAF) entre dos períodos.
 * Versión modificada: NO añade partidas agregadas como "Utilidad Neta",
 * "Ajustes no efectivo", "Aumento Cuentas por Cobrar", "Aumento Activo No Corriente",
 * "Aumento Proveedores", "Aumento Deuda Largo Plazo" ni "Aporte/Incremento Patrimonio"
 * en las listas de Orígenes/Aplicaciones. Esas partidas siguen disponibles en el
 * apartado 'detalle' pero no se repiten en los listados principales.
 *
 * @param int $periodo_anterior periodo base (A). Si no existe, pasarlo como 0.
 * @param int $periodo_actual periodo comparación (B).
 * @return array Estructura con 'origenes','aplicaciones','total_origen','total_aplicacion',
 *               'detalle','saldo_inicial_efectivo','saldo_final_efectivo','diferencia_origen_aplicacion'
 */
public function calcularEOAF(int $periodo_anterior, int $periodo_actual): array
{
    $has_prev = ($periodo_anterior > 0 && $periodo_anterior !== $periodo_actual);

    // 1) Obtener saldos de balance por cuenta para ambos periodos
    $sql = "
        SELECT c.id_cuenta, c.codigo_cuenta, c.nombre_cuenta, c.tipo_cuenta,
               COALESCE(bg_prev.saldo, 0) AS saldo_anterior,
               COALESCE(bg_curr.saldo, 0) AS saldo_actual
        FROM cuentas_contables c
        LEFT JOIN balance_general bg_curr ON bg_curr.id_cuenta = c.id_cuenta AND bg_curr.id_periodo = ?
        LEFT JOIN balance_general bg_prev ON bg_prev.id_cuenta = c.id_cuenta AND bg_prev.id_periodo = ?
        ORDER BY CASE WHEN c.codigo_cuenta <> '' THEN c.codigo_cuenta ELSE LPAD(c.id_cuenta,6,'0') END
    ";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([$periodo_actual, $periodo_anterior]);
    $cuentas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2) Inicializar acumuladores
    $origenes = [];
    $aplicaciones = [];
    $total_origen = 0.0;
    $total_aplicacion = 0.0;

    // componentes para detalle EOAF
    $delta_cxc = 0.0;
    $delta_inventario = 0.0;
    $delta_proveedores = 0.0;
    $delta_activo_no_corriente = 0.0;
    $delta_pasivo_no_corriente = 0.0;
    $delta_patrimonio = 0.0;

    // Clasificación por patrones de nombre/código (ajustable según plan de cuentas)
    foreach ($cuentas as $c) {
        $an = (float)$c['saldo_anterior'];
        $ac = (float)$c['saldo_actual'];
        $delta = $ac - $an;
        if (!$has_prev) $delta = 0.0; // si no hay periodo anterior, no hay variaciones

        $nombre = mb_strtolower($c['nombre_cuenta'] ?? '');
        $codigo = (string)($c['codigo_cuenta'] ?? '');
        $clave_partida = trim(($c['codigo_cuenta'] ?? '') . ' ' . ($c['nombre_cuenta'] ?? ''));

        // Acumular cambios para partidas específicas (CxC, Inventario, Proveedores, Activo No Corriente, Deuda LT, Patrimonio)
        if (str_contains($nombre, 'cliente') || str_contains($nombre, 'cuentas por cobrar')) {
            $delta_cxc += $delta;
        }
        if (str_contains($nombre, 'inventario') || str_contains($nombre, 'mercanc')) {
            $delta_inventario += $delta;
        }
        if (str_contains($nombre, 'proveedor') || str_contains($nombre, 'cuentas por pagar')) {
            $delta_proveedores += $delta;
        }
        if ($c['tipo_cuenta'] === 'activo_no_corriente' || str_starts_with($codigo, '1.2') || str_starts_with($codigo, '1.3')) {
            $delta_activo_no_corriente += $delta;
        }
        if ($c['tipo_cuenta'] === 'pasivo_no_corriente' || str_starts_with($codigo, '2.2') || str_contains($nombre, 'deuda') || str_contains($nombre, 'largo plazo')) {
            $delta_pasivo_no_corriente += $delta;
        }
        if ($c['tipo_cuenta'] === 'patrimonio' || str_starts_with($codigo, '3')) {
            $delta_patrimonio += $delta;
        }

        // Clasificación origen/aplicación por tipo_cuenta (por cuenta individual)
        if ($delta != 0.0) {
            if ($c['tipo_cuenta'] === 'activo_corriente' || $c['tipo_cuenta'] === 'activo_no_corriente' || str_starts_with($codigo, '1')) {
                // Activo: aumento => Aplicación, disminución => Origen
                if ($delta > 0.0) {
                    $aplicaciones[$clave_partida] = ($aplicaciones[$clave_partida] ?? 0.0) + $delta;
                    $total_aplicacion += $delta;
                } else {
                    $origenes[$clave_partida] = ($origenes[$clave_partida] ?? 0.0) + (-$delta);
                    $total_origen += -$delta;
                }
            } else {
                // Pasivo o Patrimonio: aumento => Origen, disminución => Aplicación
                if ($delta > 0.0) {
                    $origenes[$clave_partida] = ($origenes[$clave_partida] ?? 0.0) + $delta;
                    $total_origen += $delta;
                } else {
                    $aplicaciones[$clave_partida] = ($aplicaciones[$clave_partida] ?? 0.0) + (-$delta);
                    $total_aplicacion += -$delta;
                }
            }
        }
    }

    // 3) Calcular utilidades y ajustes no efectivos (operación) — sólo para detalle, NO los añadimos a origenes/aplicaciones
    $ingresos = $this->sumERPorPrefijo($periodo_actual, '4');
    $costos = $this->sumERPorPrefijo($periodo_actual, '5');
    $gastos = $this->sumERPorPrefijo($periodo_actual, '6');
    $impuestos = $this->sumERWhere($periodo_actual, "er.id_cuenta = ?", [123]);
    $utilidad_neta = $ingresos - $costos - $gastos + $impuestos;

    // Ajustes no efectivo: buscar en ER por nombre 'Depreci' y 'Provisi' (si existen)
    $stmt = $this->pdo->prepare("
        SELECT COALESCE(SUM(er.monto),0) AS monto
        FROM estado_resultados er
        JOIN cuentas_contables c ON er.id_cuenta = c.id_cuenta
        WHERE er.id_periodo = ? AND (LOWER(c.nombre_cuenta) LIKE ?)
    ");
    // Depreciacion
    $stmt->execute([$periodo_actual, '%depreci%']);
    $depreciacion = (float)$stmt->fetchColumn();
    // Provisiones
    $stmt->execute([$periodo_actual, '%provisi%']);
    $provisiones = (float)$stmt->fetchColumn();
    $ajustes_no_efectivo_total = $depreciacion + $provisiones;

    // Cambios en capital de trabajo (solo para detalle)
    $cambios_ct_total = $delta_cxc + $delta_inventario - $delta_proveedores;

    // Flujo operativo (método indirecto aproximado) — para detalle
    $flujo_operativo = $utilidad_neta + $ajustes_no_efectivo_total - $cambios_ct_total;

    // 4) Inversión: cambio en activo no corriente (para detalle)
    $cambio_activo_no_corriente = $delta_activo_no_corriente;
    $flujo_inversion = -$cambio_activo_no_corriente;

    // 5) Financiamiento: cambios en deuda LT y patrimonio (para detalle)
    $delta_deuda_lt = $delta_pasivo_no_corriente;
    $delta_patrimonio_total = $delta_patrimonio;
    $flujo_financiamiento = $delta_deuda_lt + $delta_patrimonio_total;

    // 6) Saldos inicial y final de efectivo (caja y bancos: codigo 1.1.01.% o nombre Caja/Bancos)
    $stmt = $this->pdo->prepare("
        SELECT COALESCE(SUM(bg.saldo),0) FROM balance_general bg
        JOIN cuentas_contables c ON bg.id_cuenta = c.id_cuenta
        WHERE bg.id_periodo = ? AND (c.codigo_cuenta LIKE '1.1.01.%' OR LOWER(c.nombre_cuenta) LIKE ? OR LOWER(c.nombre_cuenta) LIKE ?)
    ");
    $stmt->execute([$periodo_anterior, '%caja%', '%banco%']);
    $saldo_inicial_efectivo = (float)$stmt->fetchColumn();

    $stmt->execute([$periodo_actual, '%caja%', '%banco%']);
    $saldo_final_efectivo = (float)$stmt->fetchColumn();

    // 7) Recalcular totales (ya acumulados por cuenta)
    $total_origen = array_sum($origenes);
    $total_aplicacion = array_sum($aplicaciones);
    $diferencia = $total_origen - $total_aplicacion;

    // Preparar detalle final (sin duplicar partidas en origenes/aplicaciones)
    $detalle = [
        'operacion' => [
            'utilidad_neta' => $utilidad_neta,
            'ajustes_no_efectivo' => [
                'depreciacion' => $depreciacion,
                'provisiones' => $provisiones,
                'total' => $ajustes_no_efectivo_total
            ],
            'cambios_capital_trabajo' => [
                'delta_cxc' => $delta_cxc,
                'delta_inventario' => $delta_inventario,
                'delta_proveedores' => $delta_proveedores,
                'total' => $cambios_ct_total
            ],
            'flujo_operativo' => $flujo_operativo
        ],
        'inversion' => [
            'cambio_activo_no_corriente' => $cambio_activo_no_corriente,
            'flujo_inversion' => $flujo_inversion
        ],
        'financiamiento' => [
            'delta_deuda_lt' => $delta_deuda_lt,
            'delta_patrimonio' => $delta_patrimonio_total,
            'flujo_financiamiento' => $flujo_financiamiento
        ]
    ];

    return [
        'origenes' => $origenes,
        'aplicaciones' => $aplicaciones,
        'total_origen' => $total_origen,
        'total_aplicacion' => $total_aplicacion,
        'detalle' => $detalle,
        'saldo_inicial_efectivo' => $saldo_inicial_efectivo,
        'saldo_final_efectivo' => $saldo_final_efectivo,
        'diferencia_origen_aplicacion' => $diferencia
    ];
}





    public function calcularAnalisisHorizontalCompleto(int $periodo_actual, int $periodo_anterior): array
    {
        $sql = "
            SELECT
                c.codigo_cuenta,
                c.nombre_cuenta,

                COALESCE(bg_prev.saldo, 0) AS saldo_anterior_balance,
                COALESCE(bg_curr.saldo, 0) AS saldo_actual_balance,

                COALESCE(er_prev.monto, 0) AS saldo_anterior_resultado,
                COALESCE(er_curr.monto, 0) AS saldo_actual_resultado,

                -- Suma balance + resultado (para cuentas que figuren en ambos o en uno)
                (COALESCE(bg_prev.saldo, 0) + COALESCE(er_prev.monto, 0)) AS valor_anterior,
                (COALESCE(bg_curr.saldo, 0) + COALESCE(er_curr.monto, 0)) AS valor_actual,

                -- diferencia y variacion porcentual
                ( (COALESCE(bg_curr.saldo,0) + COALESCE(er_curr.monto,0)) - (COALESCE(bg_prev.saldo,0) + COALESCE(er_prev.monto,0)) ) AS diferencia,
                CASE
                    WHEN (COALESCE(bg_prev.saldo,0) + COALESCE(er_prev.monto,0)) <> 0
                    THEN ROUND(
                        (
                            (COALESCE(bg_curr.saldo,0) + COALESCE(er_curr.monto,0)) - (COALESCE(bg_prev.saldo,0) + COALESCE(er_prev.monto,0))
                        ) / (COALESCE(bg_prev.saldo,0) + COALESCE(er_prev.monto,0)) * 100
                    , 2)
                    ELSE NULL
                END AS variacion_porcentual

            FROM cuentas_contables c
            LEFT JOIN balance_general bg_curr ON bg_curr.id_cuenta = c.id_cuenta AND bg_curr.id_periodo = ?
            LEFT JOIN balance_general bg_prev ON bg_prev.id_cuenta = c.id_cuenta AND bg_prev.id_periodo = ?
            LEFT JOIN estado_resultados er_curr ON er_curr.id_cuenta = c.id_cuenta AND er_curr.id_periodo = ?
            LEFT JOIN estado_resultados er_prev ON er_prev.id_cuenta = c.id_cuenta AND er_prev.id_periodo = ?
            WHERE c.es_total = 0
            ORDER BY
                -- ordenar por codigo si existe (si no, por id_cuenta)
                CASE WHEN c.codigo_cuenta <> '' THEN c.codigo_cuenta ELSE LPAD(c.id_cuenta, 6, '0') END
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$periodo_actual, $periodo_anterior, $periodo_actual, $periodo_anterior]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalizamos tipos (floats) para evitar operaciones sobre arrays/strings en la view
        foreach ($rows as &$r) {
            $r['valor_anterior'] = (float) ($r['valor_anterior'] ?? 0);
            $r['valor_actual']  = (float) ($r['valor_actual'] ?? 0);
            $r['diferencia']    = (float) ($r['diferencia'] ?? 0);
            // variacion_porcentual puede ser NULL -> dejar así
            if (!is_null($r['variacion_porcentual'])) $r['variacion_porcentual'] = (float) $r['variacion_porcentual'];
        }
        unset($r);

        return $rows;
    }
    
    /**
 * Calcula el Flujo de Efectivo por el método indirecto entre dos períodos.
 *
 * Estructura devuelta (lista pensada para renderizar similar a la hoja que enviaste):
 * [
 *   'operacion' => [
 *       'utilidad_neta' => float,
 *       'ajustes_no_efectivo' => ['depreciacion'=>float,'provisiones'=>float,'total'=>float],
 *       'entradas' => ['Cobro Clientes' => float, ...],
 *       'salidas'  => ['Pago Inventario' => float, ...],
 *       'total_entradas' => float,
 *       'total_salidas' => float,
 *       'neto_operativo' => float   // utilidad_neta + ajustes - (salidas - entradas)
 *   ],
 *   'inversion' => [
 *       'entradas' => [...],   // p. ej. venta de activo fijo
 *       'salidas'  => [...],   // p. ej. compra de maquinaria
 *       'total_entradas' => float,
 *       'total_salidas' => float,
 *       'neto_inversion' => float
 *   ],
 *   'financiamiento' => [
 *       'entradas' => [...],   // p. ej. aumento deuda LT, aportes de capital
 *       'salidas'  => [...],   // p. ej. pago dividendos, amortización deuda
 *       'total_entradas' => float,
 *       'total_salidas' => float,
 *       'neto_financiamiento' => float
 *   ],
 *   'incremento_neto_efectivo' => float, // suma neta de las tres actividades
 *   'saldo_inicial_efectivo' => float,
 *   'saldo_final_efectivo' => float,
 *   'reconciliacion_diff' => float // diferencia entre incremento_neto_efectivo y (saldo_final - saldo_inicial)
 * ]
 *
 * Nota metodológica (resumen):
 * - Partimos de la utilidad neta (ER) y la ajustamos por partidas no monetarias (depreciación, provisiones).
 * - Añadimos/castigamos los cambios en capital de trabajo: ↓Cxc = entrada, ↑Inv = salida, ↑Prov = entrada, ↓Prov = salida.
 * - Inversiones: aumentos en activo no corriente = salidas (compras); disminuciones = entradas (ventas).
 * - Financiamiento: aumento pasivo no corriente / patrimonio = entradas; disminuciones y pagos (dividendos, amortizaciones) = salidas.
 *
 * @param int $periodoA periodo base (anterior)
 * @param int $periodoB periodo actual (comparación)
 * @return array
 */
/**
 * Calcula el Flujo de Efectivo por el método indirecto entre dos períodos.
 * Version modificada: mapea partidas a cuentas específicas y muestra cuenta (codigo + nombre).
 *
 * @param int $periodoA periodo base (anterior)
 * @param int $periodoB periodo actual (comparación)
 * @return array Estructura detallada para renderizar (operacion/inversion/financiamiento + saldos/reconciliacion)
 */
public function calcularFlujoEfectivoIndirecto(int $periodoA, int $periodoB): array
{
    // Helper: suma saldo en balance para periodo y patrón de búsqueda sobre nombre o código (LIKE)
    $getSaldoLike = function(int $periodo, string $pattern) {
        $sql = "SELECT COALESCE(SUM(bg.saldo),0) FROM balance_general bg
                JOIN cuentas_contables c ON bg.id_cuenta = c.id_cuenta
                WHERE bg.id_periodo = ? AND (LOWER(c.nombre_cuenta) LIKE ? OR c.codigo_cuenta LIKE ?)";
        $stmt = $this->pdo->prepare($sql);
        $like = '%' . mb_strtolower($pattern) . '%';
        $stmt->execute([$periodo, $like, $pattern . '%']);
        return (float) $stmt->fetchColumn();
    };

    // Helper: obtener código y nombre de la cuenta que coincida (primer resultado)
    $getCuentaInfo = function(string $pattern) {
        $sql = "SELECT c.codigo_cuenta, c.nombre_cuenta FROM cuentas_contables c
                WHERE LOWER(c.nombre_cuenta) LIKE ? OR c.codigo_cuenta LIKE ?
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $like = '%' . mb_strtolower($pattern) . '%';
        $stmt->execute([$like, $pattern . '%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: ['codigo_cuenta' => '', 'nombre_cuenta' => $pattern];
    };

    // 1) Saldos inicial / final de caja y bancos
    $stmt = $this->pdo->prepare("
        SELECT COALESCE(SUM(bg.saldo),0) FROM balance_general bg
        JOIN cuentas_contables c ON bg.id_cuenta = c.id_cuenta
        WHERE bg.id_periodo = ? AND (c.codigo_cuenta LIKE '1.1.01.%' OR LOWER(c.nombre_cuenta) LIKE ? OR LOWER(c.nombre_cuenta) LIKE ?)
    ");
    $stmt->execute([$periodoA, '%caja%', '%banco%']);
    $saldo_inicial = (float)$stmt->fetchColumn();
    $stmt->execute([$periodoB, '%caja%', '%banco%']);
    $saldo_final = (float)$stmt->fetchColumn();

    // 2) Utilidad neta (periodo B)
    $ingresos = $this->sumERPorPrefijo($periodoB, '4');
    $costos = $this->sumERPorPrefijo($periodoB, '5');
    $gastos = $this->sumERPorPrefijo($periodoB, '6');
    $impuestos_er = $this->sumERWhere($periodoB, "er.id_cuenta = ?", [123]); // si aplica en tu esquema
    $utilidad_neta = $ingresos - $costos - $gastos + $impuestos_er;

    // 3) Ajustes no efectivo
    $stmt = $this->pdo->prepare("
        SELECT COALESCE(SUM(er.monto),0) FROM estado_resultados er
        JOIN cuentas_contables c ON er.id_cuenta = c.id_cuenta
        WHERE er.id_periodo = ? AND LOWER(c.nombre_cuenta) LIKE ?
    ");
    $stmt->execute([$periodoB, '%depreci%']);
    $depreciacion = (float)$stmt->fetchColumn();
    $stmt->execute([$periodoB, '%provisi%']);
    $provisiones = (float)$stmt->fetchColumn();
    $stmt->execute([$periodoB, '%ajuste no efectivo%']);
    $otros_no_efectivo = (float)$stmt->fetchColumn();
    $ajustes_no_efectivo = $depreciacion + $provisiones + $otros_no_efectivo;

    // 4) Cambios en capital de trabajo (B - A) por patrones
    $cxcA = $getSaldoLike($periodoA, 'cliente');
    $cxcB = $getSaldoLike($periodoB, 'cliente');
    $delta_cxc = $cxcB - $cxcA;

    // IMPORTANTE: usar nombre exacto en BD para inventario: "Mercancías en Almacén"
    $invA = $getSaldoLike($periodoA, 'mercancías en almacén');
    $invB = $getSaldoLike($periodoB, 'mercancías en almacén');
    $delta_inv = $invB - $invA;

    $provA = $getSaldoLike($periodoA, 'proveedor');
    $provB = $getSaldoLike($periodoB, 'proveedor');
    $delta_prov = $provB - $provA;

    // Documentos por pagar: usar código exacto en BD '2.1.06.01' (Documentos por Pagar - Corto Plazo)
    $docA = $getSaldoLike($periodoA, '2.1.06.01');
    $docB = $getSaldoLike($periodoB, '2.1.06.01');
    $delta_doc = $docB - $docA;

    // Deudas acumuladas = Impuestos por Pagar (acumulados)
    $impA = $getSaldoLike($periodoA, 'impuestos por pagar');
    $impB = $getSaldoLike($periodoB, 'impuestos por pagar');
    $delta_impuestos_acumulados = $impB - $impA;

    /* ===========================
       ACTIVIDAD DE INVERSIÓN (mapeos)
       =========================== */
    $mapInv = [
        'Mobiliario y Equipo' => 'Pago adquisición de maquinaria',
        'Terreno' => 'Pago por adquisición de mobiliario',
        'Edificios' => 'Mobiliario y accesorios',
        'Vehículos de Empresa' => 'Vehiculo',
        'Vehículos en Leasing' => 'Otros'
    ];
    $inv_entradas = [];
    $inv_salidas = [];
    foreach ($mapInv as $cuentaNombre => $label) {
        $saldoA = $getSaldoLike($periodoA, $cuentaNombre);
        $saldoB = $getSaldoLike($periodoB, $cuentaNombre);
        $delta = $saldoB - $saldoA;
        $info = $getCuentaInfo($cuentaNombre);
        $partidaLabel = $label . ' — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: $cuentaNombre);
        if ($delta < 0.0) $inv_entradas[$partidaLabel] = round(-$delta, 2);
        elseif ($delta > 0.0) $inv_salidas[$partidaLabel] = round($delta, 2);
    }
    $total_inv_entradas = array_sum($inv_entradas);
    $total_inv_salidas = array_sum($inv_salidas);
    $neto_inversion = $total_inv_entradas - $total_inv_salidas;

    /* ===========================
       ACTIVIDAD DE FINANCIAMIENTO (mapeos)
       =========================== */
    $delta_prestamos_hipotecarios = $getSaldoLike($periodoB, 'préstamos hipotecarios') - $getSaldoLike($periodoA, 'préstamos hipotecarios');
    $delta_acciones_comunes = $getSaldoLike($periodoB, 'acciones comunes') - $getSaldoLike($periodoA, 'acciones comunes');
    $delta_capital_pagado_exceso = $getSaldoLike($periodoB, 'capital pagado en exceso del valor') - $getSaldoLike($periodoA, 'capital pagado en exceso del valor');

    $fin_entradas = [];
    $fin_salidas = [];

    if ($delta_prestamos_hipotecarios > 0.0) {
        $info = $getCuentaInfo('préstamos hipotecarios');
        $fin_entradas['Deuda largo plazo (Préstamos Hipotecarios) — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: 'Préstamos Hipotecarios')] = round($delta_prestamos_hipotecarios, 2);
    } elseif ($delta_prestamos_hipotecarios < 0.0) {
        $info = $getCuentaInfo('préstamos hipotecarios');
        $fin_salidas['Pago Deuda largo plazo (Préstamos Hipotecarios) — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: 'Préstamos Hipotecarios')] = round(-$delta_prestamos_hipotecarios, 2);
    }

    if ($delta_acciones_comunes > 0.0) {
        $info = $getCuentaInfo('acciones comunes');
        $fin_entradas['Acciones Comunes — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: 'Acciones Comunes')] = round($delta_acciones_comunes, 2);
    } elseif ($delta_acciones_comunes < 0.0) {
        $info = $getCuentaInfo('acciones comunes');
        $fin_salidas['Reducción Acciones Comunes — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: 'Acciones Comunes')] = round(-$delta_acciones_comunes, 2);
    }

    if ($delta_capital_pagado_exceso > 0.0) {
        $info = $getCuentaInfo('capital pagado en exceso del valor');
        $fin_entradas['Aporte/Incremento Patrimonio (Capital Pagado en Exceso del Valor a la Par) — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: 'Capital Pagado en Exceso')] = round($delta_capital_pagado_exceso, 2);
    } elseif ($delta_capital_pagado_exceso < 0.0) {
        $info = $getCuentaInfo('capital pagado en exceso del valor');
        $fin_salidas['Reducción Capital Pagado en Exceso — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: 'Capital Pagado en Exceso')] = round(-$delta_capital_pagado_exceso, 2);
    }

    /* ================================
       CÁLCULO REAL DEL PAGO DE DIVIDENDOS
       Pago Dividendos = Utilidad Neta – variación de Utilidades Acumuladas
    ================================= */
    $uaA = $getSaldoLike($periodoA, 'utilidades acumuladas');
    $uaB = $getSaldoLike($periodoB, 'utilidades acumuladas');
    $deltaUA = $uaB - $uaA;
    $pago_dividendos = $utilidad_neta - $deltaUA;
    if ($pago_dividendos > 0.0) {
        $infoUA = $getCuentaInfo('utilidades acumuladas');
        $fin_salidas['Pago de dividendos — ' . ($infoUA['codigo_cuenta'] ?: '') . ' ' . ($infoUA['nombre_cuenta'] ?: 'Utilidades Acumuladas')] = round($pago_dividendos, 2);
    }

    $total_fin_entradas = array_sum($fin_entradas);
    $total_fin_salidas = array_sum($fin_salidas);
    $neto_financiamiento = $total_fin_entradas - $total_fin_salidas;

    /* ===========================
       ACTIVIDAD DE OPERACIÓN
       - NOTA: solo garantizamos sumar explícitamente las VARIACIONES de:
         * Mercancías en Almacén (inventario)
         * 2.1.06.01 Documentos por Pagar - Corto Plazo
       - Las demás partidas se toman de las entradas/salidas creadas por etiquetas.
       =========================== */
    $oper_entradas = [];
    $oper_salidas = [];

    // Cobro Clientes (disminución de CxC = entrada)
    if ($delta_cxc < 0.0) {
        $info = $getCuentaInfo('cliente');
        $lbl = 'Cobro Clientes — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: 'Clientes');
        $oper_entradas[$lbl] = round(-$delta_cxc, 2);
    } elseif ($delta_cxc > 0.0) {
        $info = $getCuentaInfo('cliente');
        $lbl = 'Aumento Cuentas por Cobrar — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: 'Clientes');
        $oper_salidas[$lbl] = round($delta_cxc, 2);
    }

    // Inventario: usar "Mercancías en Almacén"
    if ($delta_inv > 0.0) {
        $info = $getCuentaInfo('mercancías en almacén');
        $oper_salidas['Pago Inventario — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: 'Mercancías en Almacén')] = round($delta_inv, 2);
    } elseif ($delta_inv < 0.0) {
        $info = $getCuentaInfo('mercancías en almacén');
        $oper_entradas['Pago Inventario — Disminución — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: 'Mercancías en Almacén')] = round(-$delta_inv, 2);
    }

    // Proveedores: aumento = entrada, disminución = salida
    if ($delta_prov > 0.0) {
        $info = $getCuentaInfo('proveedor');
        $oper_entradas['Aumento Proveedores (Ctas por Pagar) — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: 'Proveedores')] = round($delta_prov, 2);
    } elseif ($delta_prov < 0.0) {
        $info = $getCuentaInfo('proveedor');
        $oper_salidas['Disminución Proveedores (Pago) — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: 'Proveedores')] = round(-$delta_prov, 2);
    }

    // Documentos por pagar: usar código 2.1.06.01
    if ($delta_doc > 0.0) {
        $info = $getCuentaInfo('2.1.06.01');
        $oper_entradas['Aumento Documentos por Pagar — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: 'Documentos por Pagar - Corto Plazo')] = round($delta_doc, 2);
    } elseif ($delta_doc < 0.0) {
        $info = $getCuentaInfo('2.1.06.01');
        $oper_salidas['Pago Documentos por Pagar — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: 'Documentos por Pagar - Corto Plazo')] = round(-$delta_doc, 2);
    }

    // Deudas acumuladas <- Impuestos por Pagar (acumulados)
    if ($delta_impuestos_acumulados > 0.0) {
        $info = $getCuentaInfo('impuestos por pagar');
        $oper_entradas['Aumento Impuestos por Pagar (Deudas acumuladas) — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: 'Impuestos por Pagar')] = round($delta_impuestos_acumulados, 2);
    } elseif ($delta_impuestos_acumulados < 0.0) {
        $info = $getCuentaInfo('impuestos por pagar');
        $oper_salidas['Pago Impuestos por Pagar (Deudas acumuladas) — ' . ($info['codigo_cuenta'] ?: '') . ' ' . ($info['nombre_cuenta'] ?: 'Impuestos por Pagar')] = round(-$delta_impuestos_acumulados, 2);
    }

    // -------------------------------
    // Totales de operación
    // - Sumamos los arrays (etiquetas) y AÑADIMOS EXPLÍCITAMENTE solo delta_inv y delta_doc
    //   para garantizar que esas variaciones siempre se reflejen en los totales.
    // -------------------------------
    $sum_oper_entradas_from_arrays = array_sum($oper_entradas);
    $sum_oper_salidas_from_arrays = array_sum($oper_salidas);

    // Sólo estas dos variaciones se agregan a totales por seguridad (sin duplicar si ya están en arrays)
    // Calculamos lo que falta por agregar comparando lo que ya está en arrays con los delta totales.
    // Para evitar duplicados, restamos lo que ya se incluyó en arrays (si coincide por etiqueta).
    // Simplificamos: si la etiqueta correspondiente existe, asumimos que el array ya contiene la variación.
    $already_have_inv_in_arrays = false;
    foreach ($oper_entradas + $oper_salidas as $k => $v) {
        if (stripos($k, 'mercancías en almacén') !== false || stripos($k, 'mercancias en almacen') !== false || stripos($k, 'inventario') !== false) {
            $already_have_inv_in_arrays = true;
            break;
        }
    }
    $already_have_doc_in_arrays = false;
    foreach ($oper_entradas + $oper_salidas as $k => $v) {
        if (stripos($k, '2.1.06.01') !== false || stripos($k, 'documentos por pagar') !== false) {
            $already_have_doc_in_arrays = true;
            break;
        }
    }

    $extra_from_inv = 0.0;
    if (!$already_have_inv_in_arrays) {
        if ($delta_inv < 0.0) $extra_from_inv += -$delta_inv; // entrada
        elseif ($delta_inv > 0.0) $extra_from_inv -= $delta_inv; // salida (se sumará a salidas más abajo)
    }

    $extra_from_doc = 0.0;
    if (!$already_have_doc_in_arrays) {
        if ($delta_doc > 0.0) $extra_from_doc += $delta_doc; // entrada
        elseif ($delta_doc < 0.0) $extra_from_doc -= $delta_doc; // salida (negativo para salidas)
    }

    // Ahora construimos los totales garantizados:
    // Entradas: sum de arrays + (si delta_inv <0 -> -delta_inv) + (si delta_doc >0 -> delta_doc)
    // Salidas:  sum de arrays + (si delta_inv >0 -> delta_inv) + (si delta_doc <0 -> -delta_doc)
    $total_oper_entradas = $sum_oper_entradas_from_arrays;
    $total_oper_salidas  = $sum_oper_salidas_from_arrays;

    if (!$already_have_inv_in_arrays) {
        if ($delta_inv < 0.0) $total_oper_entradas += -$delta_inv;
        elseif ($delta_inv > 0.0) $total_oper_salidas += $delta_inv;
    }

    if (!$already_have_doc_in_arrays) {
        if ($delta_doc > 0.0) $total_oper_entradas += $delta_doc;
        elseif ($delta_doc < 0.0) $total_oper_salidas += -$delta_doc;
    }

    // redondeo finales
    $total_oper_entradas = round($total_oper_entradas, 2);
    $total_oper_salidas = round($total_oper_salidas, 2);

    // neto operativo: utilidad neta + ajustes no efectivo + entradas operativas - salidas operativas
    $neto_operativo = $utilidad_neta + $ajustes_no_efectivo + $total_oper_entradas - $total_oper_salidas;

    // 8) Incremento neto total
    $incremento_neto_efectivo = $neto_operativo + $neto_inversion + $neto_financiamiento;

    // === RECÁLCULO FORZADO DEL SALDO FINAL PARA QUE RECONCILIE PERFECTAMENTE ===
    $saldo_final_calculado = $saldo_inicial + $incremento_neto_efectivo;

    // Diferencia entre lo que dice el balance y lo que dice el flujo (útil para depuración)
    $saldo_final_del_balance = $saldo_final; // este es el que traías de la BD
    $reconciliacion_diff = round($saldo_final_calculado - $saldo_final_del_balance, 2);

    // Si hay diferencia significativa, podrías querer registrarla o alertarla
    // (opcional) if (abs($reconciliacion_diff) > 0.01) { log... }

    // 9) Formatear y devolver
    return [
        'operacion' => [
            'utilidad_neta' => round($utilidad_neta, 2),
            'ajustes_no_efectivo' => [
                'depreciacion' => round($depreciacion, 2),
                'provisiones' => round($provisiones, 2),
                'otros_no_efectivo' => round($otros_no_efectivo, 2),
                'total' => round($ajustes_no_efectivo, 2)
            ],
            'entradas' => $oper_entradas,
            'salidas' => $oper_salidas,
            'total_entradas' => $total_oper_entradas,
            'total_salidas' => $total_oper_salidas,
            'neto_operativo' => round($neto_operativo, 2)
        ],
        'inversion' => [
            'entradas' => $inv_entradas,
            'salidas' => $inv_salidas,
            'total_entradas' => round($total_inv_entradas, 2),
            'total_salidas' => round($total_inv_salidas, 2),
            'neto_inversion' => round($neto_inversion, 2)
        ],
        'financiamiento' => [
            'entradas' => $fin_entradas,
            'salidas' => $fin_salidas,
            'total_entradas' => round($total_fin_entradas, 2),
            'total_salidas' => round($total_fin_salidas, 2),
            'neto_financiamiento' => round($neto_financiamiento, 2)
        ],

        // Aquí está el cambio clave:
        'incremento_neto_efectivo' => round($incremento_neto_efectivo, 2),
        'saldo_inicial_efectivo'   => round($saldo_inicial, 2),
        'saldo_final_efectivo'     => round($saldo_final_calculado, 2),        // ← ahora es calculado
        'saldo_final_balance'      => round($saldo_final_del_balance, 2),     // ← valor real del balance (opcional, para auditoría)
        'reconciliacion_diff'      => $reconciliacion_diff,                   // ← diferencia (idealmente 0.00)
        'reconciliacion_ok'        => abs($reconciliacion_diff) <= 0.01       // ← bandera útil en frontend
    ];
}

/**
 * Calcula el Flujo de Efectivo por método directo entre dos períodos (A -> B).
 *
 * Toma como referencia las mismas partidas usadas en el método indirecto y el
 * Excel "Metodirecto.xlsx" para producir:
 *  - Entradas de efectivo por clientes
 *  - Salidas de efectivo (proveedores, sueldos y salarios, intereses, impuestos, otros)
 *  - Flujo operativo (entradas - salidas)
 *  - Flujo de inversión (cambios en activo no corriente)
 *  - Flujo de financiamiento (cambios en pasivo no corriente y patrimonio)
 *  - Saldos inicial y final de efectivo, incremento y reconciliación
 *
 * @param int $periodoA periodo anterior (para variaciones)
 * @param int $periodoB periodo actual
 * @return array
 */
public function calcularFlujoEfectivoDirecto(int $periodoA, int $periodoB): array
{
    // Reutilizar helpers del método indirecto (mínimo necesario)
    $getSaldoLike = function(int $periodo, string $pattern) {
        $sql = "SELECT COALESCE(SUM(bg.saldo),0) FROM balance_general bg
                JOIN cuentas_contables c ON bg.id_cuenta = c.id_cuenta
                WHERE bg.id_periodo = ? AND (LOWER(c.nombre_cuenta) LIKE ? OR c.codigo_cuenta LIKE ?)";
        $stmt = $this->pdo->prepare($sql);
        $like = '%' . mb_strtolower($pattern) . '%';
        $stmt->execute([$periodo, $like, $pattern . '%']);
        return (float) $stmt->fetchColumn();
    };

    // Sumas de resultados por prefijo (ventas, costos, gastos)
    $sumPref = function(int $periodo, string $prefijo) {
        return $this->sumERPorPrefijo($periodo, $prefijo);
    };

    // Saldo de efectivo (Caja y Bancos)
    $saldoA = $getSaldoLike($periodoA, 'caja');
    $saldoA += $getSaldoLike($periodoA, 'bancos');
    $saldoB = $getSaldoLike($periodoB, 'caja');
    $saldoB += $getSaldoLike($periodoB, 'bancos');

    // Clientes (cuentas por cobrar) y variación
    $cxcA = $getSaldoLike($periodoA, 'cliente');
    $cxcB = $getSaldoLike($periodoB, 'cliente');
    $delta_cxc = $cxcB - $cxcA;

    // Proveedores (cuentas por pagar) y variación
    $provA = $getSaldoLike($periodoA, 'proveedor');
    $provB = $getSaldoLike($periodoB, 'proveedor');
    $delta_prov = $provB - $provA;

    // Inventarios y variación
    $invA = $getSaldoLike($periodoA, 'mercancías');
    if ($invA == 0.0) $invA = $getSaldoLike($periodoA, 'inventario');
    $invB = $getSaldoLike($periodoB, 'mercancías');
    if ($invB == 0.0) $invB = $getSaldoLike($periodoB, 'inventario');
    $delta_inv = $invB - $invA;

    // Ventas, costo de ventas y gastos
    $ventas = $sumPref($periodoB, '4'); // ingresos
    $costo_ventas = $sumPref($periodoB, '5');
    $gastos_operativos = $sumPref($periodoB, '6');

    // Ajustes no efectivo (depreciación, provisiones) - para separar de pagos en efectivo
    $stmt = $this->pdo->prepare("
        SELECT COALESCE(SUM(er.monto),0) FROM estado_resultados er
        JOIN cuentas_contables c ON er.id_cuenta = c.id_cuenta
        WHERE er.id_periodo = ? AND (LOWER(c.nombre_cuenta) LIKE ? OR LOWER(c.nombre_cuenta) LIKE ?)
    ");
    $stmt->execute([$periodoB, '%depreci%', '%provisi%']);
    $ajustes_no_efectivo = (float)$stmt->fetchColumn();

    // Entradas de efectivo: cobros a clientes (ventas netas ajustadas por variación de cuentas por cobrar)
    // Aproximación: Cobros = Ventas - aumento en CxC (si delta_cxc > 0 disminuye caja)
    $cobros_clientes = $ventas - $delta_cxc;

    // Salidas de efectivo operativas:
    // - Pagos a proveedores (aprox): costo de ventas + aumento de inventario - aumento de proveedores
    $pagos_proveedores = $costo_ventas + $delta_inv - $delta_prov;

    // - Pagos de sueldos/gastos en efectivo: gastos operativos menos gastos no efectivos (ej. depreciación)
    $pagos_gastos_operativos = $gastos_operativos - $ajustes_no_efectivo;
    if ($pagos_gastos_operativos < 0) $pagos_gastos_operativos = 0.0;

    // - Pagos de intereses e impuestos (aprox. buscar cuentas por nombre)
    $intereses_pagados = $getSaldoLike($periodoB, 'interes pagado');
    if ($intereses_pagados == 0.0) $intereses_pagados = $getSaldoLike($periodoB, 'gasto por intereses');
    $impuestos_pagados = $getSaldoLike($periodoB, 'impuesto') + $getSaldoLike($periodoB, 'impuestos');

    // Otros pagos/entradas (donativos, cobros varios)
    $otros_entradas = $getSaldoLike($periodoB, 'ingreso por') + $getSaldoLike($periodoB, 'otros ingresos');
    $otros_salidas = $getSaldoLike($periodoB, 'pago por') + $getSaldoLike($periodoB, 'otros gastos');

    // Construcción de arrays para detalle (etiquetas claras)
    $operacion_entradas = [
        'Cobros de clientes (aprox)' => round($cobros_clientes, 2),
    ];
    $operacion_salidas = [
        'Pagos a proveedores (aprox)' => round($pagos_proveedores, 2),
        'Pagos gastos operativos (sueldos, administración) (aprox)' => round($pagos_gastos_operativos, 2),
        'Intereses pagados (aprox)' => round($intereses_pagados, 2),
        'Impuestos pagados (aprox)' => round($impuestos_pagados, 2),
    ];
    if ($otros_entradas != 0.0) $operacion_entradas['Otras entradas'] = round($otros_entradas, 2);
    if ($otros_salidas != 0.0) $operacion_salidas['Otras salidas'] = round($otros_salidas, 2);

    $total_entradas = array_sum($operacion_entradas);
    $total_salidas = array_sum($operacion_salidas);
    $flujo_operativo = $total_entradas - $total_salidas;

    // Inversión y financiamiento: reutilizar la lógica del método indirecto (variaciones)
    // Cambio en activo no corriente
    $activo_no_corriente_A = $this->sumBalanceWhere($periodoA, "c.tipo_cuenta = 'activo_no_corriente'");
    $activo_no_corriente_B = $this->sumBalanceWhere($periodoB, "c.tipo_cuenta = 'activo_no_corriente'");
    $cambio_activo_no_corriente = $activo_no_corriente_B - $activo_no_corriente_A;
    $flujo_inversion = -$cambio_activo_no_corriente;

    // Cambios en pasivo no corriente y patrimonio
    $pasivo_no_corriente_A = $this->sumBalanceWhere($periodoA, "c.tipo_cuenta = 'pasivo_no_corriente'");
    $pasivo_no_corriente_B = $this->sumBalanceWhere($periodoB, "c.tipo_cuenta = 'pasivo_no_corriente'");
    $delta_pasivo_nc = $pasivo_no_corriente_B - $pasivo_no_corriente_A;

    $patrimonio_A = $this->getTotalPatrimonio($periodoA);
    $patrimonio_B = $this->getTotalPatrimonio($periodoB);
    $delta_patrimonio = $patrimonio_B - $patrimonio_A;

    $flujo_financiamiento = $delta_pasivo_nc + $delta_patrimonio;

    // Reconciliación: comparar incremento en efectivo calculado con cambio real de saldos
    $incremento_calculado = $flujo_operativo + $flujo_inversion + $flujo_financiamiento;
    $reconciliacion = ($saldoB - $saldoA) - $incremento_calculado;

    return [
        'operacion' => [
            'entradas' => $operacion_entradas,
            'salidas' => $operacion_salidas,
            'total_entradas' => round($total_entradas,2),
            'total_salidas' => round($total_salidas,2),
            'flujo_operativo' => round($flujo_operativo,2),
        ],
        'inversion' => [
            'cambio_activo_no_corriente' => round($cambio_activo_no_corriente,2),
            'flujo_inversion' => round($flujo_inversion,2),
        ],
        'financiamiento' => [
            'delta_pasivo_no_corriente' => round($delta_pasivo_nc,2),
            'delta_patrimonio' => round($delta_patrimonio,2),
            'flujo_financiamiento' => round($flujo_financiamiento,2),
        ],
        'saldo_inicial_efectivo' => round($saldoA,2),
        'saldo_final_efectivo' => round($saldoB,2),
        'incremento_neto_efectivo' => round($incremento_calculado,2),
        'reconciliacion_diff' => round($reconciliacion,2),
    ];
}







    /* ============================
       EOAF: FUENTES Y USOS (estado origen y aplicacion fondos)
       ============================ */



    

    /* ============================
       GET - devuelve todas las razones en un array (para UI)
       ============================ */

        /* ============================
       GET - devuelve todas las razones en un array (para UI)
       ============================ */

    public function getRazonesCompletas(int $id_periodo): array {
        // Calculamos todas las razones/medidas disponibles
        $liquidez_corriente = $this->calcularLiquidezCorriente($id_periodo);
        $capital_neto_trabajo = $this->calcularCNT($id_periodo);
        $prueba_acida = $this->calcularPruebaAcida($id_periodo);
        $cash_ratio = $this->calcularCashRatio($id_periodo);
        $capital_trabajo = $this->calcularCapitalTrabajo($id_periodo);

        $margen_bruto = $this->calcularMargenBruto($id_periodo);         // devuelve en % (según implementación)
        $margen_operativo = $this->calcularMargenOperativo($id_periodo); // decimal
        $margen_neto = $this->calcularMargenNeto($id_periodo);           // decimal

        $roe = $this->calcularRendimientoSobrePatrimonio($id_periodo);   // devuelve en % (según implementación)

        $rotacion_activos = $this->calcularRotacionActivos($id_periodo);
        $rotacion_inventarios = $this->calcularRotacionInventarios($id_periodo);
        $rotacion_cxc = $this->calcularRotacionCuentasPorCobrar($id_periodo);
        $dias_cartera = $this->calcularDiasCartera($id_periodo);

        $endeudamiento = $this->calcularEndeudamiento($id_periodo);
        $razon_pasivo_capital = $this->calcularRazonPasivoCapital($id_periodo);
        $solvencia = $this->calcularSolvencia($id_periodo);

        // Análisis vertical (devuelven arrays con filas)
        $analisis_vertical_balance = $this->calcularAnalisisVerticalBalance($id_periodo);
        $analisis_vertical_resultados = $this->calcularAnalisisVerticalResultados($id_periodo);
        

        return [
            // Liquidez / Efectivo
            'liquidez_corriente' => $liquidez_corriente,
            'capital_neto_trabajo' => $capital_neto_trabajo,
            'prueba_acida' => $prueba_acida,
            'cash_ratio' => $cash_ratio,
            'capital_trabajo' => $capital_trabajo,

            // Rentabilidad / Márgenes
            'margen_bruto' => $margen_bruto,
            'margen_operativo' => $margen_operativo,
            'margen_neto' => $margen_neto,
            'ROE' => $roe, // rendimiento sobre patrimonio

            // Rotaciones / Eficiencia
            'rotacion_activos' => $rotacion_activos,
            'rotacion_inventarios' => $rotacion_inventarios,
            'rotacion_cuentas_por_cobrar' => $rotacion_cxc,
            'dias_cartera' => $dias_cartera,

            // Estructura / Solvencia
            'endeudamiento' => $endeudamiento,
            'razon_pasivo_capital' => $razon_pasivo_capital,
            'solvencia' => $solvencia,

            // Análisis vertical (tablas completas)
            'analisis_vertical_balance' => $analisis_vertical_balance,
            'analisis_vertical_resultados' => $analisis_vertical_resultados,
            'dupont' => $this->calcularAnalisisDupont($id_periodo),
        ];
    }

}
