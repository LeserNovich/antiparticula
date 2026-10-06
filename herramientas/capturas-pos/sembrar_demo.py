"""Llena una COPIA de la base del Punto de Venta con un negocio de ejemplo para las capturas.

BORRA las ventas, productos y turnos de la base que recibe: úsalo solo sobre una copia
en una carpeta temporal, nunca sobre la base del proyecto Java ni la de un cliente.

    python3 sembrar_demo.py <copia>/BD/datos.db

Formatos de la app: importes en centavos, cantidades en milésimas (1000 = 1 pieza o 1 kg),
PZ_O_KG 0 = pieza / 1 = kg, fechas 'dd-MM-yyyy HH:mm:ss', GANANCIA en pesos.
"""
import random
import sqlite3
import sys
from datetime import datetime, timedelta

db = sys.argv[1]
if 'punto_venta' in db.replace('\\', '/').lower() or 'nuevopunto' in db.lower():
    sys.exit('Esa ruta parece la base del proyecto del Punto de Venta: usa una copia en una carpeta temporal.')
random.seed(2026)
c = sqlite3.connect(db)

for t in ['DETALLE_TICKETS', 'TICKETS', 'DETALLE_APARTADOS', 'ABONOS_APARTADOS', 'APARTADOS',
          'DETALLE_PRESUPUESTOS', 'PRESUPUESTOS', 'CLIENTES_FISCALES', 'MOVIMIENTOS_CAJA',
          'TURNOS', 'PRODUCTOS', 'CATEGORIAS']:
    c.execute(f'DELETE FROM {t}')

c.execute("UPDATE NEGOCIO SET INFO_1=?, INFO_2=?, INFO_3=NULL, INFO_4=NULL, INFO_5=NULL",
          ('Abarrotes La Esperanza', 'Av. Hidalgo 45, Centro'))

# (código, nombre, categoría, costo, precio, existencia, mínimo, kg)
productos = [
    ('7501000100017', 'Refresco de cola 600 ml', 'Bebidas', 13.00, 18.00, 96, 24, 0),
    ('7501000100024', 'Agua natural 1 L', 'Bebidas', 7.50, 12.00, 60, 20, 0),
    ('7501000100031', 'Jugo de naranja 1 L', 'Bebidas', 21.00, 29.00, 18, 6, 0),
    ('7501000100048', 'Leche entera 1 L', 'Lácteos', 22.00, 28.00, 40, 12, 0),
    ('7501000100055', 'Yogur natural 1 kg', 'Lácteos', 34.00, 45.00, 4, 6, 0),
    ('7501000100062', 'Queso fresco', 'Lácteos', 105.00, 140.00, 6.5, 2, 1),
    ('7501000100079', 'Pan de caja grande', 'Abarrotes', 41.00, 52.00, 14, 6, 0),
    ('7501000100086', 'Huevo blanco', 'Abarrotes', 38.00, 48.00, 22, 5, 1),
    ('7501000100093', 'Tortilla de maíz', 'Abarrotes', 19.00, 24.00, 15, 5, 1),
    ('7501000100109', 'Frijol negro 1 kg', 'Abarrotes', 29.00, 38.00, 25, 8, 0),
    ('7501000100116', 'Arroz 1 kg', 'Abarrotes', 23.00, 30.00, 30, 8, 0),
    ('7501000100123', 'Aceite vegetal 1 L', 'Abarrotes', 36.00, 45.00, 20, 6, 0),
    ('7501000100130', 'Azúcar estándar 1 kg', 'Abarrotes', 25.00, 32.00, 3, 8, 0),
    ('7501000100147', 'Café soluble 120 g', 'Abarrotes', 52.00, 65.00, 12, 4, 0),
    ('7501000100154', 'Atún en agua 140 g', 'Abarrotes', 17.00, 22.00, 36, 10, 0),
    ('7501000100161', 'Sopa de pasta 200 g', 'Abarrotes', 7.00, 10.00, 48, 12, 0),
    ('7501000100178', 'Papas fritas 45 g', 'Botanas', 14.00, 19.00, 40, 12, 0),
    ('7501000100185', 'Galletas surtidas', 'Botanas', 18.00, 24.00, 26, 8, 0),
    ('7501000100192', 'Cacahuates enchilados 100 g', 'Botanas', 11.00, 16.00, 30, 8, 0),
    ('7501000100208', 'Jabón de barra', 'Limpieza', 12.00, 16.00, 24, 6, 0),
    ('7501000100215', 'Detergente en polvo 1 kg', 'Limpieza', 33.00, 42.00, 5, 6, 0),
    ('7501000100222', 'Papel higiénico 4 rollos', 'Limpieza', 27.00, 35.00, 18, 6, 0),
    ('7501000100239', 'Jamón de pavo', 'Salchichonería', 110.00, 145.00, 4.2, 1.5, 1),
    ('7501000100246', 'Salchicha de pavo', 'Salchichonería', 70.00, 92.00, 5.0, 1.5, 1),
]
for cat in sorted({p[2] for p in productos}):
    c.execute('INSERT INTO CATEGORIAS (CATEGORIA) VALUES (?)', (cat,))
for cod, nom, cat, costo, precio, exist, minimo, kg in productos:
    c.execute('INSERT INTO PRODUCTOS (CODIGO_DE_BARRAS, NOMBRE_PRODUCTO, CATEGORIA, PRECIO_COSTO_KG_PZ, '
              'PRECIO_VENTA_KG_PZ, USA_INVENTARIO, CANTIDAD, PZ_O_KG, STOCK_MINIMO) VALUES (?,?,?,?,?,1,?,?,?)',
              (cod, nom, cat, round(costo * 100), round(precio * 100), round(exist * 1000), kg, max(1, round(minimo))))  # STOCK_MINIMO va en unidades enteras

# Turno abierto hoy: así Vender no pide el dinero inicial
hoy = datetime(2026, 9, 23)
c.execute("INSERT INTO TURNOS (USUARIO, FECHA_INICIO, FECHA_FIN, DINERO_INICIO, DINERO_FINAL, DINERO_HABER) "
          "VALUES ('Administrador', ?, NULL, '50000', NULL, NULL)", (hoy.strftime('%d-%m-%Y') + ' 08:00:00',))
turno_hoy = str(c.execute('SELECT MAX(ID) FROM TURNOS').fetchone()[0])

# Popularidad: los básicos se venden más
pesos = [9, 6, 3, 8, 2, 3, 5, 6, 7, 4, 4, 3, 3, 2, 3, 4, 6, 4, 3, 2, 2, 3, 3, 2]
horas = [8, 9, 9, 10, 10, 11, 12, 13, 13, 14, 14, 15, 16, 17, 18, 18, 19, 19, 20, 20, 21]

dia = datetime(2026, 1, 1)
tickets = 0
while dia <= hoy:
    avance = (dia - datetime(2026, 1, 1)).days / (hoy - datetime(2026, 1, 1)).days
    n = int(8 + 20 * avance + random.randint(-3, 4) + (5 if dia.weekday() >= 5 else 0))
    if dia == hoy:
        n = 14  # el día aún no termina
    for _ in range(max(n, 3)):
        h = random.choice(horas if dia != hoy else [h for h in horas if h <= 13])
        fecha = dia.replace(hour=h, minute=random.randint(0, 59), second=random.randint(0, 59))
        lineas = random.choices(range(len(productos)), weights=pesos, k=random.choice([1, 1, 2, 2, 3, 3, 4, 5]))
        detalle = {}
        for i in lineas:
            detalle[i] = detalle.get(i, 0) + (random.choice([250, 500, 750, 1000, 1500]) if productos[i][7]
                                              else random.choice([1000, 1000, 1000, 2000, 3000]))
        total = 0
        filas = []
        for i, cant in detalle.items():
            cod, nom, cat, costo, precio, *_ , kg = productos[i]
            sub = round(precio * 100 * cant / 1000)
            total += sub
            filas.append((cod, nom, cant, round(costo * 100), round(precio * 100), sub,
                          round((precio - costo) * cant / 1000, 4), kg))
        pago = next(b for b in [total, 5000, 10000, 20000, 50000, 100000, 200000] if b >= total)
        cur = c.execute('INSERT INTO TICKETS (NOMBRE_TICKET, FECHA, TOTAL, PAGO_CON, CAMBIO, CAJERO, TURNO, ESTATUS, DEVUELTO) '
                        'VALUES (?,?,?,?,?,?,?,0,0)',
                        (f'Ticket {tickets + 1}', fecha.strftime('%d-%m-%Y %H:%M:%S'), total, pago, pago - total,
                         'Administrador', turno_hoy if dia == hoy else '99'))
        tid = cur.lastrowid
        for f in filas:
            c.execute('INSERT INTO DETALLE_TICKETS (ID_TICKET, CODIGO_DE_BARRAS, NOMBRE_PRODUCTO, CANTIDAD, COSTO, PRECIO, '
                      'SUBTOTAL, GANANCIA, PZ_O_KG) VALUES (?,?,?,?,?,?,?,?,?)', (tid,) + f)
        tickets += 1
    dia += timedelta(days=1)

c.commit()
print('productos', len(productos), 'tickets', tickets,
      'ventas sep $', c.execute("SELECT SUM(TOTAL)/100.0 FROM TICKETS WHERE FECHA LIKE '%-09-2026%'").fetchone()[0])
