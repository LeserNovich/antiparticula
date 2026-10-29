# Configuración de TikTok Ads para el punto de venta

Oct 27, 2026 · @Erick Ricardo Gonzalez Manuel

Tus anuncios se ven en el celular, pero tu punto de venta se instala en Windows o Mac. Por eso la campaña optimiza por **registros** en una landing que manda el instalador al correo o WhatsApp, y la **suscripción pagada** se reporta de vuelta a TikTok desde tu servidor para que el algoritmo aprenda a buscar compradores, no solo curiosos.

## El recorrido del cliente

&#91;embedded content: recorrido del cliente · 6 pasos en 2 dispositivos\]

Las dos piezas que sostienen el embudo son el envío del instalador y el reporte de tu servidor: sin ellas, TikTok solo ve registros y nunca sabe quién pagó.

## Medición: lo que debe estar listo antes de gastar

Sin estos eventos TikTok solo puede optimizar por clics, y un clic en el celular casi nunca termina en una suscripción de escritorio. Instala el **Pixel de TikTok** en la landing y conecta la **Events API** desde tu servidor: el pago ocurre en la computadora, días después, y solo tu servidor puede avisarle a TikTok.

| Paso del cliente | Evento estándar | Se dispara desde | Objetivo que lo acepta | Para qué lo usas |
| --- | --- | --- | --- | --- |
| Ve la landing | `ViewContent` | Pixel | Ventas, Clientes potenciales | Audiencia de retargeting |
| Deja correo y WhatsApp para recibir el instalador | `CompleteRegistration` | Pixel + Events API | Clientes potenciales | Evento de optimización al arrancar |
| Descarga el instalador en la computadora | `Download` | Pixel (botón de descarga) | Ventas | Medir cuántos registros sí instalan |
| Activa el programa o inicia la prueba | `StartTrial` | Events API (tu servidor) | Clientes potenciales | Optimización intermedia |
| Paga la suscripción | `Subscribe` con `value` y `currency: MXN` | Events API (tu servidor) | Ventas, Clientes potenciales | Optimización final y audiencias similares |

- **El puente celular → computadora:** guarda el `ttclid` que TikTok agrega a la URL del anuncio junto con el registro del lead. Envíalo en cada evento de servidor, más correo y teléfono en SHA-256, para que TikTok atribuya la suscripción hecha en la computadora al anuncio visto en el celular.
- Usa el mismo `event_id` en Pixel y Events API para que TikTok no cuente doble.
- Activa la coincidencia avanzada (Advanced Matching) en el Pixel.
- Antes de lanzar, confirma cada evento en Events Manager → Probar eventos.

## Campañas y grupos de anuncios

Arranca con una sola campaña de **Generación de clientes potenciales** hacia tu landing, optimizada por registros, con dos grupos de anuncios. Agrega retargeting cuando haya audiencia y sube el evento a suscripción cuando haya volumen.

| Campaña | Objetivo | Destino | Evento de optimización | Grupos de anuncios | Cuándo |
| --- | --- | --- | --- | --- | --- |
| 1. Prospección | Generación de clientes potenciales | Sitio web (landing) | `CompleteRegistration` | A: amplio (solo país y edad) · B: intereses y comportamientos | Día 1 |
| 2. Formulario instantáneo (prueba) | Generación de clientes potenciales | Formulario instantáneo dentro de TikTok | Clientes potenciales; luego eventos de CRM (registró → descargó → pagó) | 1 grupo amplio | Semana 3, si el registro en la landing sale caro |
| 3. Retargeting | Ventas | Sitio web (página de precios) | `Subscribe` | Visitantes de la landing, vieron 50 % o más de un video, registrados que no han pagado | Semana 4, cuando Ads Manager muestre tamaño suficiente de audiencia |

**Presupuesto**

- Mínimos de TikTok: más de 50 USD diarios por campaña y más de 20 USD diarios por grupo de anuncios. Para conversiones web TikTok sugiere al menos 30 USD por grupo al día.
- Presupuesto real: la fase de aprendizaje necesita unas 50 conversiones por grupo a la semana, así que presupuesto diario por grupo ≈ (50 ÷ 7) × costo por registro. Ejemplo ilustrativo: si cada registro cuesta $60 MXN, necesitas cerca de $430 MXN diarios por grupo.
- Usa presupuesto **diario**, no total, y activa el presupuesto de campaña Smart+ para que TikTok reparta entre los grupos A y B.
- Para escalar, sube máximo \~30 % por ajuste y no más de una vez cada dos días; cambios bruscos reinician el aprendizaje.

**Puja y atribución**

- Puja: *Costo más bajo* (entrega máxima) las primeras dos semanas. Cuando el costo por registro se estabilice, prueba *Tope de costo* cerca del promedio que observaste.
- Ventana de atribución: 7 días después del clic y 1 día después de la vista. La suscripción puede llegar después, así que mídela también en tu propio sistema.
- El formulario instantáneo suele dar registros más baratos pero menos calificados; por eso conviene devolverle a TikTok quién sí descargó y pagó mediante eventos de CRM.

## Segmentación del grupo de anuncios

El filtro de "tiene computadora y negocio" no existe en TikTok: los anuncios se ven en la app del celular. Ese filtro lo hacen tu video y tu landing; la segmentación solo acota país, edad e intención.

| Campo | Grupo A — amplio | Grupo B — intereses |
| --- | --- | --- |
| Ubicación | México (o solo los estados donde das soporte) | Igual que A |
| Idioma | Español | Español |
| Edad | 25–54 | 25–54; prueba 55+ si tu cliente típico es dueño de negocio tradicional |
| Género | Todos | Todos |
| Intereses | Ninguno: TikTok busca solo | Busca en el selector: emprendimiento, pequeñas empresas, negocios, finanzas, tecnología |
| Comportamientos | Ninguno | Interacción con videos de Negocios y Tecnología; hashtags como #emprendedor #negociopropio #tiendita #abarrotes #pyme #puntodeventa (ajústalos a tu giro) |
| Audiencias personalizadas | Excluir registrados y suscriptores en cuanto existan | Igual que A |
| Sistema operativo y dispositivo | Todos; no limites a iOS ni Android | Todos |
| Ubicaciones (placements) | Solo TikTok; desactiva Pangle y Global App Bundle | Solo TikTok |
| Expansión de segmentación | Activada | Desactivada las dos primeras semanas, para comparar A contra B limpio |
| Comentarios | Activados y moderados (prueba social) | Activados |
| Descarga del video | Desactivada | Desactivada |

Si prefieres que TikTok lo maneje todo, Smart+ automatiza segmentación, ubicaciones y presupuesto; úsalo como el grupo A en lugar de configurarlo a mano. Cuando tengas unos cientos de suscriptores, sube su lista (correo o teléfono) como audiencia personalizada y crea una audiencia similar (lookalike) para un grupo C.

## Creativos: cómo usar tus videos

Cada video debe dejar claro en los primeros segundos que es un programa para computadora. Eso aleja a quien busca una app de celular y baja tu costo por suscripción.

- **Formato:** vertical 9:16 (1080 × 1920), con subtítulos en español y audio.
- **Gancho (0–3 s):** el dolor concreto, por ejemplo "¿Sigues cerrando caja en un cuaderno?".
- **Cuerpo:** la pantalla real del sistema: cobrar, imprimir ticket, revisar inventario, corte de caja.
- **Texto en pantalla fijo:** "Para Windows y Mac" más tu oferta ("Pruébalo gratis" si tienes prueba).
- **Cierre:** "Regístrate y te mandamos el instalador a tu correo".
- **Spark Ads:** si los videos ya están publicados en tu cuenta de TikTok, promuévelos como Spark Ads; conservan likes y comentarios y llevan tráfico a tu perfil.
- **Rotación:** sube de 3 a 5 videos por grupo y deja que TikTok reparta. Tras una semana, reemplaza los de CTR más bajo.
- **Botón (CTA):** "Registrarte" o "Más información". Evita "Descargar": en el celular promete algo que ahí no se puede hacer.

Textos de anuncio, todos con menos de 100 caracteres:

| Ángulo | Texto |
| --- | --- |
| Beneficio directo | Cobra, controla tu inventario y cierra caja en minutos. Para Windows y Mac. |
| Dolor | ¿Tu negocio aún lleva las ventas en cuaderno? Instala nuestro punto de venta en tu compu. |
| Instrucción clara | Punto de venta para Windows o Mac. Regístrate y te mandamos el instalador al correo. |

## Landing y seguimiento

La landing detecta el dispositivo: en el celular pide correo y WhatsApp para enviar el instalador; en la computadora muestra el botón de descarga directo. El seguimiento convierte ese registro en una instalación.

1. Encabezado que repite el gancho del video, con "Para Windows y Mac" visible.
2. Formulario corto: nombre, correo, WhatsApp y giro del negocio (tienda, restaurante, farmacia…). Incluye la casilla de aceptación para recibir mensajes por WhatsApp.
3. Botón "Envíame el instalador" → dispara `CompleteRegistration`.
4. Página de gracias: "Abre tu correo en la computadora" más un enlace corto fácil de teclear.
5. En computadora: botones "Descargar para Windows" y "Descargar para Mac" → disparan `Download`.

| Cuándo | Canal | Mensaje |
| --- | --- | --- |
| Al registrarse | Correo + WhatsApp | Enlace de descarga y video de instalación de un minuto |
| 24 h después, si no descargó | WhatsApp | "¿Ya lo instalaste? Te ayudo en 5 minutos" |
| Día 3 | Correo | Tutorial: alta de productos y primera venta |
| 2 días antes de que termine la prueba | Correo + WhatsApp | Oferta para suscribirse (por ejemplo, descuento en plan anual) |

## Plan de 8 semanas

&#91;embedded content: plan de 8 semanas · 4 fases y 3 controles\]

Cambia el evento de optimización solo cuando cada grupo junte unas 50 conversiones del nuevo evento por semana; antes de eso, TikTok no tiene datos suficientes para aprender.

## Cuándo apagar o escalar

La decisión final se toma con el costo por suscripción, no con el costo por registro. Calcula primero cuánto puedes pagar por cliente:

```latex
\text{CAC máximo} \approx \frac{\text{precio mensual} \times \text{meses promedio que se queda un cliente}}{3}
```

| Métrica | Qué te dice | Cada cuánto | Acción |
| --- | --- | --- | --- |
| CTR por video | Si el gancho funciona | Semanal, por video | Reemplaza los videos con CTR claramente más bajo que el resto |
| Costo por registro | Eficiencia de la campaña | Semanal | Compáralo contra tu tasa registro → suscripción para estimar el CAC |
| % registro → descarga | Si el puente celular → computadora funciona | Semanal | Si es bajo, mejora correo y WhatsApp antes de tocar los anuncios |
| % descarga → suscripción | Si la prueba convence | Semanal | Ajusta onboarding, precio u oferta |
| Costo por suscripción (CAC) | Si TikTok es rentable | Cada 2 semanas | Escala si está bajo tu CAC máximo; apaga el grupo si lo supera dos semanas seguidas |
| Frecuencia | Fatiga de los anuncios | Semanal | Sube videos nuevos cuando suba la frecuencia y baje el CTR |

## Lo que ya está decidido (27 de octubre de 2026)

Esto manda sobre lo de arriba donde no coincida.

- **Oferta:** el programa es **gratis para siempre** (no es una prueba). Lo que se vende es Premium: $149 al mes, $1,290 al año o $3,490 para siempre; varias sucursales, $299 al mes o $2,990 al año.
- **Presupuesto:** entre $5,000 y $12,000 al mes. TikTok pide más de 20 USD diarios por grupo (unos $380), así que va **una campaña con un solo grupo** (amplio), con el presupuesto en el grupo. El grupo B de intereses y el retargeting quedan para después.
- **Evento de optimización:** `CompleteRegistration` (el registro con WhatsApp). La descarga y la compra se miden, pero con este presupuesto no juntan 50 por semana.
- **Solo México** al empezar.
- **Página de destino:** `antiparticula.com/gratis`. En el celular pide WhatsApp, giro y nombre (opcional) y da un link personal (`antiparticula.com/d/CÓDIGO`) para abrir en la computadora. En la computadora enseña la descarga directa. Aviso de privacidad en `antiparticula.com/privacidad`.
- **Medición instalada:** Pixel `DBG6JEBC77U8I4RJ0MD0` + Events API desde el servidor (token probado el 27 de octubre). `CompleteRegistration` y `Download` salen por los dos lados con el mismo `event_id`. La descarga hecha en la computadora lleva el WhatsApp cifrado y el `ttclid` del anuncio que se vio en el celular.
- **Creativos:** dos Spark Ads, con códigos ya aplicados en Ads Manager.
  - Video 2 (septiembre 2025, «Mi tienda de abarrotes solo vendió $23 pesos»): el principal.
  - Video 1 (abril 2024, «diseños feos de los ochentas»): el segundo; cambiarlo si a la semana su CTR queda muy abajo.
  - Ninguno dice «gratis» ni «Windows y Mac»: eso lo hacen el botón y la página.
  - En la semana 2, grabar un tercer video pensado para anuncio.
- **Seguimiento:** el link no sale de tu número: la página se lo da a cada persona. Tú escribes desde `/admin/` → Registros (mensaje ya armado) a quien se registró y no descargó (24 h). Quien responda «BAJA» se marca ahí y no recibe más mensajes.

### Pendiente
- [x] Correr la migración de la base y subir la página `/gratis` (27 de octubre).
- [x] Probar el recorrido completo con «Probar eventos»: `CompleteRegistration` y `Download` llegaron por navegador y por servidor (27 de octubre).
- [x] Quitar `tiktok_prueba` de `config_db.php` y borrar los registros de prueba (27 de octubre).
- [ ] Fijar un comentario en cada video: «Es gratis para Windows y Mac 👉 antiparticula.com/gratis».
- [ ] Crear la campaña en Ads Manager (Generación de clientes potenciales → sitio web → `CompleteRegistration`).
- [ ] Avisar la compra de Premium a TikTok desde el servidor (para el retargeting de la semana 4).
- [x] Pestaña **Registros** en `/admin/` (27 de octubre): mensaje de seguimiento armado en WhatsApp, BAJA y borrar datos.

## Fuentes

- [Eventos estándar y parámetros — TikTok Ads Manager](https://ads.tiktok.com/help/article/standard-events-parameters)
- [Acerca de los presupuestos — TikTok Ads Manager](https://ads.tiktok.com/help/article/budget?lang=es)
- [Presupuesto recomendado — TikTok for Business México](https://ads.tiktok.com/business/es-MX/how-it-works/budgeting)
- [Acerca del objetivo Ventas — TikTok Ads Manager](https://ads.tiktok.com/help/article/sales-advertising-objective-tiktok)
- [Cómo elegir el objetivo correcto — TikTok Ads Manager](https://ads.tiktok.com/help/article/choose-right-objective)
- [Generación de clientes potenciales y eventos de CRM vía Zapier — TikTok Ads Manager](https://ads.tiktok.com/resources/help/article/integrate-tiktok-lead-generation-zapier)
- [Opciones de segmentación — TikTok for Business](https://ads.tiktok.com/business/en-US/blog/how-to-connect-with-your-target-audience-on-tiktok)
- [TikTok Ads Manager 101 y Smart+ — TikTok for Business](https://ads.tiktok.com/business/en-US/blog/how-to-get-started-with-tiktok-ads-manager-your-step-by-step-guide)
- [Fase de aprendizaje y costos — Trendtrack](https://www.trendtrack.io/es/blog-post/cuanto-cuesta-una-campana-de-tiktok-ads)
