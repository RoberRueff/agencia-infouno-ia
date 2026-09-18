-- Migración 006 — Agrega ROAS y CPL del mes a vista_objetivos_progreso.
-- Idempotente: CREATE OR REPLACE VIEW se puede re-correr sin romper.
-- ROAS/CPL del mes se calculan acá en SQL (no en PHP), con guard contra cero.
CREATE OR REPLACE VIEW vista_objetivos_progreso AS
SELECT
  o.id,
  o.cliente_id,
  c.nombre_empresa,
  o.mes_anio,
  o.presupuesto_asignado,
  o.meta_leads,
  o.meta_ventas,
  COALESCE(SUM(m.inversion_total), 0) AS inversion_real_mes,
  COALESCE(SUM(m.leads_totales), 0) AS leads_reales_mes,
  COALESCE(SUM(m.monto_venta), 0) AS ventas_reales_mes,
  CASE WHEN o.presupuesto_asignado = 0 THEN 0
       ELSE COALESCE(SUM(m.inversion_total), 0) / o.presupuesto_asignado
  END AS pct_cumplimiento_presupuesto,
  CASE WHEN o.meta_ventas = 0 THEN 0
       ELSE COALESCE(SUM(m.monto_venta), 0) / o.meta_ventas
  END AS pct_cumplimiento_ventas,
  CASE WHEN COALESCE(SUM(m.inversion_total), 0) = 0 THEN 0
       ELSE COALESCE(SUM(m.monto_venta), 0) / COALESCE(SUM(m.inversion_total), 0)
  END AS roas_mes,
  CASE WHEN COALESCE(SUM(m.leads_totales), 0) = 0 THEN 0
       ELSE COALESCE(SUM(m.inversion_total), 0) / COALESCE(SUM(m.leads_totales), 0)
  END AS cpl_mes
FROM objetivos_comerciales o
INNER JOIN clientes c ON c.id = o.cliente_id
LEFT JOIN metricas_diarias m
  ON m.cliente_id = o.cliente_id
  AND YEAR(m.fecha) = YEAR(o.mes_anio)
  AND MONTH(m.fecha) = MONTH(o.mes_anio)
GROUP BY o.id, o.cliente_id, c.nombre_empresa, o.mes_anio, o.presupuesto_asignado, o.meta_leads, o.meta_ventas;
