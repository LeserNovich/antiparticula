# UI/UX Designer — Memoria del Proyecto Antipartícula

## Paleta de colores aprobada
- Navy oscuro: `#202936` — botones primarios, títulos de forma
- Steel blue: `#2c6e95` — headers de sección, subtítulos, focusedBorderColor, acento
- Blanco puro: `#ffffff` — fondos de cards
- Texto secundario: `#4e4a67` — subtítulos (web)
- Fondo raíz: `rgb(240,242,245)` — gris muy suave para contrastar el card blanco
- Error: `#FF6B6B` — borderColor en validación de campos
- Links hover: cambian a navy `#202936`

## Componente: Login de aplicación Swing
- Archivo: `/mnt/d/Programas/NetBeans-21/Programas/nuevoPunto/punto_venta/punto_venta/src/raven/login/Login.java`
- Diseño de dos columnas: leftPanel (260px, gradiente) + rightPanel (380-420px, blanco)
- Gradiente left: `GradientPaint` de COLOR_NAVY a COLOR_STEEL en diagonal
- Bordes redondeados: `fillRoundRect` con radio 30. El leftPanel extiende +30px a la derecha y el rightPanel extiende -30px a la izquierda para que los bordes internos queden rectos al empalmar.
- Botón principal: `arc:25`, `background:#202936`, `foreground:WHITE`, sin borde
- Campos de texto: `arc:12`, `borderWidth:1`, `focusedBorderColor:#2c6e95`
- Botones secundarios: `arc:20`, fondo `rgb(245,245,247)`, texto COLOR_NAVY

## Patrón de card "blog-slider" trasladado a Swing
- Card externo opaco=false con `paintComponent` que dibuja fondo blanco redondeado
- Los dos sub-paneles también opacos=false con su propio `paintComponent`
- Esto permite anti-aliasing correcto en las esquinas (sin artefactos de color de fondo)
- MigLayout del card: `"fill,insets 0,gap 0"` con columnas `"[260!][380:420:grow]"`

## Stack del proyecto Swing
- FlatLaf + MigLayout + Raven UI components
- Imports necesarios para gradiente: `java.awt.GradientPaint`, `java.awt.Graphics2D`, `java.awt.RenderingHints`
- FlatClientProperties se usa para estilos inline (arc, borderWidth, focusedBorderColor, margin, font)

## Estilos canónicos — Formas con sección card (ConfiguracionGeneralForm / ModalAgregarUsuario)
- Título forma: Font BOLD 22, `foreground:#202936`, icono Unicode delante (escape Java)
- Título modal: Font BOLD 18, `foreground:#202936`, icono Unicode delante
- Subtítulo: Font ITALIC 12, `foreground:#2c6e95`, constraint `"gapbottom 18"`
- Sección card: `arc:15; border:8,8,8,8,$Component.borderColor,,15`, insets `18 16 18 16`
- Header de sección: Font BOLD 14, `foreground:#2c6e95`, constraint `"gapbottom 2"`
- Sublabel de campo: Font PLAIN 11, `foreground:mix($Label.foreground,$Label.disabledForeground,40%)`, `"gapbottom 2"`
- Campo texto: `arc:12; borderWidth:1; focusedBorderColor:#2c6e95`, height 36
- Error campo: `borderColor:#FF6B6B; borderWidth:2; arc:12; focusedBorderColor:#FF6B6B`
- Botón primario: `arc:20; background:#202936; foreground:#FFFFFF; borderWidth:0; margin:10,20,10,20` — height 45, width 220, Font BOLD 14
- Layout forma: `"wrap,fill,insets 25,gap 12", "[fill]"`
- Layout modal: `"wrap 1,fillx,insets 25", "[fill,grow]"`
- Radio buttons: cada opción en sub-panel con `arc:10; border:1,1,1,1,$Component.borderColor,,10`, descripción italic 11 gris debajo, contenedor `"insets 0,gap 20"`
- Checkboxes: descripción debajo con `gapleft 22` para alinear; Font PLAIN 13; separador JLabel(" ") font size 3
- Iconos: `\u2699` config, `\uD83C\uDFE2` negocio, `\uD83D\uDDA8` impresora, `\uD83D\uDC64` usuario — siempre Unicode escape en Java

## Patrón de formulario con tarjetas (VentanaAgregar / VentanaKilogramos)
- Layout raíz: `MigLayout("wrap 1, fill, insets 20, gap 12", "[fill, grow]")`
- Tarjeta: `MigLayout("wrap 2, fillx, insets 15, gap 8", "[140, right][grow, fill]")`
- Tarjeta style: `"arc:15; border:6,6,6,6,$Component.borderColor,,15"`
- Título de tarjeta: Font bold 13pt, color #202936, constraint `"span 2, gapbottom 8"`
- Campos JTextField: `"arc:10; borderWidth:1; focusedBorderColor:#2c6e95"`, height 38
- Campos grandes (VentanaKilogramos): `arc:12; margin:6,10,6,10`, height 45
- Campo read-only: añadir `"background:darken(@background,4%)"` al STYLE
- JSpinner: NO tiene `arc`; height 35
- JTextArea: NO usar `arc`
- Botón primario: `"background:#202936; foreground:#FFFFFF; arc:20; borderWidth:0; margin:10,20,10,20"`, height 48
- NUNCA mover `putClientProperty(PLACEHOLDER_TEXT, ...)` del constructor a initComponents
- Los marcadores GEN-BEGIN/END se eliminan; variables y event handlers se copian exactos

## Patrón de formularios popup simples (VentanaXxx — dialogs de turno/venta)
- Layout: `MigLayout("wrap 1, fill, insets 30 35 25 35", "[fill, grow]")`
- Encabezado (campoTotal): Font BOLD 24pt, color `#202936`, alineado LEFT
- Subtítulo descriptivo: FlatClientProperties STYLE `"font:italic -1; foreground:mix($Label.foreground,$Label.disabledForeground,50%)"`
- Separador: `add(jSeparator1, "growx, gapbottom 16")`
- Campos editables: `"arc:12; borderWidth:1; focusedBorderColor:#2c6e95; margin:6,10,6,10"`, height 45-50px
- Campos read-only: mismo estilo + `"background:darken(@background,3%)"`, `setEditable(false)`, `setFocusable(false)`
- Botón primario: `"background:#202936; foreground:#FFFFFF; arc:20; borderWidth:0; margin:10,20,10,20"`, height 50px, Font BOLD 14
- Botón secundario: `"arc:15; borderWidth:0; background:darken(@background,7%); margin:8,15,8,15"`, height 45px
- VentanaConfirmarVenta: agrega JLabel local `lbSubtotal` (no declarado como variable de clase) para subtítulo "TOTAL A COBRAR" centrado
- Estilos dinámicos (borderColor rojo/verde según diferencia): siguen aplicándose en event handlers, no en initComponents
- Estilos `font:+6` de labels (jLabel1, jLabel2, jLabel3): se aplican en el constructor, no en initComponents
- jLabel1 vacío (VentanaCambiarContraseniaDefecto): `add(jLabel1, "hidemode 3, height 0!")` para no ocupar espacio
- Archivos redesignados: VentanaDineroInicial, VentanaDineroFinal, VentanaConfirmarVenta, VentanaCambiarContraseniaDefecto, VentanaCerrarTurno

## Patrón de pantalla tabla con toolbar (AgregaEditaElimina)
- Archivo: `raven/forms/AgregaEditaElimina.java`
- Layout raíz: `MigLayout("fill, insets 10", "[fill]", "[fill]")` + `add(jPanel1, "grow")`
- jPanel1: `MigLayout("fill, insets 20 25 15 25", "[fill,grow]", "[][][grow]")`, style `"arc:20; background:$Table.background"`
- Fila 1 header: `JPanel(MigLayout("insets 0, fill", "[grow][]"))` con título bold+8 + badge `arc:20; background:mix($Table.background,#202936,8%); border:4,10,4,10; font:-1`
- Fila 2 toolbar: `MigLayout("insets 0, gap 6", "[grow,fill 300:300:400][pref!][pref!][10!][pref!][pref!][pref!]", "[38!]")` orden: busqueda | exportar | importar | sep | agregar | editar | eliminar
- Fila 3: jSeparator1 + Scroll con `grow`
- Campo busqueda: `arc:20; borderWidth:1; focusedBorderColor:#2c6e95; margin:5,12,5,12` + PLACEHOLDER + LEADING_ICON
- Botón agregar (primary): `arc:18; borderWidth:0; background:#202936; foreground:#FFFFFF; font:bold; margin:4,14,4,14`
- Botón editar (secondary): `arc:18; borderWidth:1; margin:4,14,4,14`
- Botón eliminar (danger): `arc:18; borderWidth:1; [light]background:mix(#e05252,@background,12%); [light]borderColor:#e05252; [light]foreground:#c0392b; [dark]...`
- Botones exportar/importar (outline): `arc:18; borderWidth:1; margin:4,10,4,10; font:-1`
- Separador visual (JLabel "|"): `[light]foreground:lighten(@foreground,60%); [dark]foreground:darken(@foreground,60%)`
- Tabla rowHeight:38, header height:36
- Las variables botonImportar, botonExportar, separador2 se declaran fuera del bloque GEN-BEGIN (al final de la clase)
- Al eliminar marcadores GEN-BEGIN/END: mover declaración de botonImportar/botonExportar/separador2 dentro de initComponents

## Notas de implementación
- `usuarioActivo` se captura antes de los ActionListeners y se usa dentro de ellos (variable efectivamente final)
- `fechaLimite` existe como variable de clase pero no se renderiza visualmente (compatibilidad con código externo)
- Toda la lógica de telemetría, turnos y sesiones debe mantenerse intacta al rediseñar
