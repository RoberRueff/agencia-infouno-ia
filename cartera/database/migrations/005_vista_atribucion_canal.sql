-- Migración 005 — Vista de atribución por canal (Google vs Meta) por cliente y mes.
-- Idempotente: CREATE OR REPLACE VIEW se puede re-correr sin romper.
-- ROAS/CPL/conversión se calculan acá en SQL (no en PHP), como el resto de las métricas.
-- Criterio temporal (deuda conocida): inversión por día de gasto, ventas/leads por mes
-- de ENTRADA del lead (cohorte), distinto del mes de cierre (caja) que usa
-- metricas_diarias. Las "ventas del mes" pueden no coincidir con la tarjeta de ROAS.
CREATE OR REPLACE VIEW vista_atribucion_canal AS
SELECT
  cliente_id, mes_anio, canal,
  SUM(inversion)    AS inversion,
  SUM(leads)        AS leads,
  SUM(ventas_cant)  AS ventas_cant,
  SUM(ventas_monto) AS ventas_monto,
  IF(SUM(inversion) = 0, 0, SUM(ventas_monto) / SUM(inversion)) AS roas,
  IF(SUM(leads)     = 0, 0, SUM(inversion) / SUM(leads))        AS cpl,
  IF(SUM(leads)     = 0, 0, SUM(ventas_cant) / SUM(leads))      AS `conversion`
FROM (
  -- Inversión Google (despivote de columna a fila).
  SELECT cliente_id, DATE_FORMAT(fecha, '%Y-%m-01') AS mes_anio, 'google' AS canal,
         inversion_google AS inversion, 0 AS leads, 0 AS ventas_cant, 0 AS ventas_monto
  FROM metricas_diarias
  UNION ALL
  -- Inversión Meta.
  SELECT cliente_id, DATE_FORMAT(fecha, '%Y-%m-01'), 'meta',
         inversion_meta, 0, 0, 0
  FROM metricas_diarias
  UNION ALL
  -- Leads y ventas atribuidas por canal de origen (fuente) y al mes de ENTRADA
  -- del lead (fecha_ingreso = cohorte), no al mes de cierre de la venta.
  SELECT cliente_id, DATE_FORMAT(fecha_ingreso, '%Y-%m-01'), fuente,
         0, 1,
         IF(estado = 'vendido', 1, 0),
         IF(estado = 'vendido', COALESCE(monto_venta, 0), 0)
  FROM leads
) unido
GROUP BY cliente_id, mes_anio, canal;
