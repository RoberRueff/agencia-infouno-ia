'use strict';

require('dotenv').config();
const express = require('express');
const cors    = require('cors');
const path    = require('path');

const app  = express();
const PORT = process.env.PORT || 3000;

app.use(cors());
app.use(express.json({ limit: '1mb' }));
app.use(express.static(path.join(__dirname, 'public')));

// ── POST /api/diagnostico ──────────────────────────────────────────────────
app.post('/api/diagnostico', async (req, res) => {
  const apiKey = process.env.GEMINI_API_KEY;
  if (!apiKey) {
    return res.status(500).json({ error: 'GEMINI_API_KEY no configurada en el servidor.' });
  }

  const d = req.body;

  // Validación mínima de campos obligatorios
  const required = ['empresa', 'contacto', 'email', 'rubro', 'productos'];
  for (const field of required) {
    if (!d[field] || typeof d[field] !== 'string' || !d[field].trim()) {
      return res.status(400).json({ error: `Campo requerido faltante: ${field}` });
    }
  }

  const systemPrompt = `Sos un consultor digital senior de Infouno, agencia argentina especializada en estrategia digital, diseño web y automatización para PyMEs.

Acabás de recibir el formulario de diagnóstico completado por un cliente potencial. Tu tarea es analizar sus respuestas y generar un Resumen Ejecutivo de Diagnóstico — Método UNO® Nivel 1 — que le sirva al equipo de Infouno para preparar una propuesta personalizada y que también motive al cliente a avanzar.

El resumen debe cubrir estos puntos (integrándolos en texto corrido, sin usar estos títulos literalmente):
1. Perfil del cliente — quiénes son, rubro, tiempo en el mercado.
2. Oportunidad principal — el mayor potencial de crecimiento digital basado en sus objetivos declarados.
3. Producto/servicio clave — cuál tiene mayor rentabilidad y cuál quieren impulsar este año.
4. Estado de recursos — qué tienen listo versus qué falta conseguir.
5. Insight de compra — la objeción o pregunta principal de sus clientes (señal directa para la propuesta).
6. Visión de éxito — qué tiene que pasar en 12 meses para que valga la pena la inversión.
7. Tres próximos pasos concretos que Infouno debería proponer.

Reglas de estilo:
- Tono: directo, profesional, cálido. Voseo argentino.
- Sin tecnicismos innecesarios.
- Máximo 400 palabras.
- No inventar información que no esté en el formulario.`;

  const userContent = buildUserMessage(d);

  try {
    const url = `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=${apiKey}`;

    const response = await fetch(url, {
      method : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        system_instruction: { parts: [{ text: systemPrompt }] },
        contents: [{ parts: [{ text: userContent }] }],
        generationConfig: {
          maxOutputTokens: 1024,
          temperature: 0.3,
          thinkingConfig: { thinkingBudget: 0 },
        },
      }),
    });

    const data = await response.json();

    if (!response.ok) {
      console.error('[Gemini error]', response.status, data);
      return res.status(response.status).json({ error: 'Error al procesar el diagnóstico. Intentá de nuevo más tarde.' });
    }

    // gemini-2.5-flash es un modelo de pensamiento: parts[0] puede ser el razonamiento
    // interno (thought:true); tomamos la concatenación de las partes de texto final.
    const parts = data.candidates?.[0]?.content?.parts ?? [];
    const diagnostico = parts
      .filter(p => !p.thought)
      .map(p => p.text ?? '')
      .join('')
      .trim();
    console.log(`[diagnóstico OK] ${d.empresa} — ${new Date().toISOString()}`);

    return res.json({ diagnostico });

  } catch (err) {
    console.error('[Proxy error]', err.message);
    return res.status(502).json({ error: 'Error de conexión con el servicio de análisis.' });
  }
});

// ── Fallback SPA ──────────────────────────────────────────────────────────
app.get('*', (_req, res) => {
  res.sendFile(path.join(__dirname, 'public', 'metodo-uno-nivel1.html'));
});

// ── Arranque ──────────────────────────────────────────────────────────────
app.listen(PORT, () => {
  console.log(`✅  Servidor corriendo en http://localhost:${PORT}`);
});

// ── Helpers ───────────────────────────────────────────────────────────────

function buildUserMessage(d) {
  const arr = (v) => Array.isArray(v) && v.length ? v.join(', ') : 'No especificado';
  const str = (v) => (v && v.trim()) ? v.trim() : 'No especificado';

  return `FORMULARIO DE DIAGNÓSTICO — MÉTODO UNO® NIVEL 1

DATOS DE CONTACTO
Empresa: ${str(d.empresa)}
Contacto: ${str(d.contacto)} (${str(d.cargo)})
Teléfono: ${str(d.telefono)}
Email: ${str(d.email)}
Sitio web: ${str(d.sitio)}
Redes sociales: ${str(d.redes)}

INFORMACIÓN GENERAL
Rubro / actividad: ${str(d.rubro)}
Antigüedad en el mercado: ${str(d.antiguedad)}

SU NEGOCIO
Productos o servicios: ${str(d.productos)}
Principales clientes: ${arr(d.clientes)}
Objetivos del sitio: ${arr(d.objetivos)}
Producto de mayor rentabilidad: ${str(d.masRentable)}
Producto que quiere vender más este año: ${str(d.venderMas)}

RECURSOS DISPONIBLES
Materiales que ya poseen: ${arr(d.recursos)}
Sitios web de referencia (estilo): ${str(d.sitiosRef)}
Principal competencia: ${str(d.competencia)}

VISIÓN Y CIERRE
Definición de éxito en 12 meses: ${str(d.exito)}
Pregunta frecuente de sus clientes antes de comprar: ${str(d.preguntaClientes)}
Información adicional: ${str(d.infoExtra)}`;
}
