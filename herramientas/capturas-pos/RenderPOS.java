import com.formdev.flatlaf.FlatLaf;
import com.formdev.flatlaf.fonts.roboto.FlatRobotoFont;
import com.formdev.flatlaf.themes.FlatMacDarkLaf;
import com.formdev.flatlaf.themes.FlatMacLightLaf;
import java.awt.*;
import java.awt.image.BufferedImage;
import java.io.File;
import java.lang.reflect.Method;
import java.util.List;
import javax.imageio.ImageIO;
import javax.swing.*;
import raven.Antiparticula.DTO.ProductosDTO;
import raven.application.Application;
import raven.forms.AgregaEditaElimina;
import raven.forms.Vender;
import raven.menu.FormManager;
import raven.model.ModelUser;

/**
 * Fotografía pantallas reales del Punto de Venta para la página web.
 * Corre contra una COPIA de la base (user.dir/BD/datos.db) y con la red bloqueada
 * por proxy, para no mandar telemetría ni sincronizar datos de ejemplo al servidor.
 * La ventana se abre fuera de la pantalla: no aparece en el escritorio (por eso la
 * lista de búsqueda se dibuja aparte, ver captura()).
 *
 * Las capturas se dibujan a ESCALA (2x por defecto): Swing vuelve a pintar la
 * ventana con un Graphics escalado, así que texto y formas salen nítidos en
 * pantallas de alta densidad (no es agrandar una imagen 1x).
 *
 * Uso: java -Dawt.useSystemAAFontSettings=on RenderPOS <prefijo_salida> <light|dark> <ancho> <alto> [escala]
 * (useSystemAAFontSettings=on: suavizado en gris; el ClearType de Windows deja
 * bordes de color que se notan al reducir la imagen en la página)
 */
public class RenderPOS {
    static int ESCALA = 2;

    public static void main(String[] a) throws Exception {
        String salida = a[0];
        boolean oscuro = "dark".equals(a[1]);
        int ancho = Integer.parseInt(a[2]), alto = Integer.parseInt(a[3]);
        if (a.length > 4) ESCALA = Integer.parseInt(a[4]);
        System.out.println("pantalla=" + Toolkit.getDefaultToolkit().getScreenSize());

        FlatRobotoFont.install();
        FlatLaf.registerCustomDefaultsSource("raven.themes");
        UIManager.put("defaultFont", new Font(FlatRobotoFont.FAMILY, Font.PLAIN, 13));
        if (oscuro) FlatMacDarkLaf.setup(); else FlatMacLightLaf.setup();

        Application[] app = new Application[1];
        EventQueue.invokeAndWait(() -> {
            app[0] = new Application();
            app[0].setAutoRequestFocus(false);
            app[0].setFocusableWindowState(false);
            app[0].setSize(ancho, alto);
            app[0].setLocation(-4000, -4000);
            app[0].setVisible(true);
        });
        Thread.sleep(2500);

        EventQueue.invokeAndWait(() -> {
            ModelUser u = ModelUser.getInstancia();
            u.setUserName("Administrador");
            u.setAdmin(true);
            FormManager.login(u);
        });
        Thread.sleep(5000); // deja pasar el aviso de stock bajo y las animaciones
        try {
            // Al entrar, la app abre Vender: se llena el carrito como si el cajero escaneara
            EventQueue.invokeAndWait(() -> {
                try {
                    Vender v = Vender.getInstancia();
                    Method poner = Vender.class.getDeclaredMethod("ponerDatoTabla", ProductosDTO.class);
                    poner.setAccessible(true);
                    String[] carrito = {"Leche entera", "Pan de caja", "Refresco de cola", "Refresco de cola",
                                        "Frijol negro", "Atún en agua", "Papas fritas", "Café soluble"};
                    for (String q : carrito) {
                        List<ProductosDTO> r = v.buscaDatos(q);
                        if (!r.isEmpty()) poner.invoke(v, r.get(0));
                        else System.out.println("sin resultado: " + q);
                    }
                } catch (Exception ex) {
                    ex.printStackTrace();
                }
            });
            Thread.sleep(2000);
            captura(app[0], salida + "_vender.png");

            // Búsqueda: escribir en el buscador dispara la lista de sugerencias,
            // igual que al teclear (el listener de Vender escucha keyReleased)
            EventQueue.invokeAndWait(() -> {
                JTextField campo = Vender.campoBusqueda;
                campo.setText("pa");
                campo.dispatchEvent(new java.awt.event.KeyEvent(campo, java.awt.event.KeyEvent.KEY_RELEASED,
                        System.currentTimeMillis(), 0, java.awt.event.KeyEvent.VK_A, 'a'));
            });
            Thread.sleep(1500);
            java.lang.reflect.Field fm = Vender.class.getDeclaredField("menu");
            fm.setAccessible(true);
            JPopupMenu sugerencias = (JPopupMenu) fm.get(Vender.getInstancia());
            System.out.println("sugerencias visibles=" + sugerencias.isVisible() + " tamaño=" + sugerencias.getSize());
            captura(app[0], salida + "_busqueda.png", sugerencias, Vender.campoBusqueda);
            EventQueue.invokeAndWait(() -> {
                sugerencias.setVisible(false);
                Vender.campoBusqueda.setText(null);
            });

            // Como lo hace el usuario: abrir el menú y elegir la pantalla
            EventQueue.invokeAndWait(() -> FormManager.showMenu());
            Thread.sleep(1500);
            EventQueue.invokeAndWait(() -> FormManager.showForm(new raven.forms.DashboardForm()));
            Thread.sleep(4000);
            captura(app[0], salida + "_resumen.png");

            EventQueue.invokeAndWait(() -> FormManager.showMenu());
            Thread.sleep(1500);
            EventQueue.invokeAndWait(() -> FormManager.showForm(new AgregaEditaElimina()));
            Thread.sleep(3500);
            captura(app[0], salida + "_productos.png");
        } catch (Exception ex) {
            ex.printStackTrace();
        }
        System.exit(0);
    }

    static void captura(JFrame f, String archivo) throws Exception {
        captura(f, archivo, null, null);
    }

    /**
     * Con la ventana fuera de la pantalla, Swing abre los menús emergentes (la lista
     * de búsqueda) como ventanas propias que printAll del marco no incluye: se dibuja
     * el menú aparte, justo debajo de su ancla, como lo muestra la app.
     */
    static void captura(JFrame f, String archivo, JPopupMenu emergente, JComponent ancla) throws Exception {
        int w = f.getWidth(), h = f.getHeight();
        BufferedImage img = new BufferedImage(w * ESCALA, h * ESCALA, BufferedImage.TYPE_INT_ARGB);
        EventQueue.invokeAndWait(() -> {
            Graphics2D g = img.createGraphics();
            g.scale(ESCALA, ESCALA);
            g.setRenderingHint(RenderingHints.KEY_ANTIALIASING, RenderingHints.VALUE_ANTIALIAS_ON);
            g.setRenderingHint(RenderingHints.KEY_TEXT_ANTIALIASING, RenderingHints.VALUE_TEXT_ANTIALIAS_ON);
            g.setRenderingHint(RenderingHints.KEY_RENDERING, RenderingHints.VALUE_RENDER_QUALITY);
            g.setRenderingHint(RenderingHints.KEY_INTERPOLATION, RenderingHints.VALUE_INTERPOLATION_BICUBIC);
            f.getRootPane().printAll(g);
            if (emergente != null && emergente.isVisible()) {
                Point p = SwingUtilities.convertPoint(ancla, 0, ancla.getHeight(), f.getRootPane());
                Graphics2D gm = (Graphics2D) g.create(p.x, p.y, emergente.getWidth(), emergente.getHeight());
                emergente.printAll(gm);
                gm.dispose();
            }
            g.dispose();
        });
        ImageIO.write(img, "png", new File(archivo));
        System.out.println("captura " + archivo + " " + img.getWidth() + "x" + img.getHeight());
    }
}
