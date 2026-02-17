#!/bin/bash

# ========================================================
# Script de Prueba - Sistema de Telemetría
# ========================================================
# Este script prueba el endpoint registrar_uso.php
# con diferentes escenarios de eventos de telemetría
# ========================================================

URL="https://www.antiparticula.com/registrar_uso.php"

echo "=========================================="
echo "Pruebas del Sistema de Telemetría"
echo "=========================================="
echo ""

# Test 1: Login - Nuevo Turno
echo "Test 1: Login - Nuevo Turno"
curl -X POST "$URL" \
  -d "tipo_evento=login" \
  -d "usuario=TestAdministrador" \
  -d "rol=administrador" \
  -d "tipo_sesion=nuevo_turno" \
  -d "negocio=Tienda de Prueba Central" \
  -d "hardware_id=TEST1234567890AB"
echo -e "\n"

sleep 1

# Test 2: Login - Continuación
echo "Test 2: Login - Continuación de Turno"
curl -X POST "$URL" \
  -d "tipo_evento=login" \
  -d "usuario=Cajero1" \
  -d "rol=cajero" \
  -d "tipo_sesion=continuacion" \
  -d "negocio=Tienda de Prueba Central" \
  -d "hardware_id=TEST1234567890AB"
echo -e "\n"

sleep 1

# Test 3: Login - Admin Override
echo "Test 3: Login - Admin Override"
curl -X POST "$URL" \
  -d "tipo_evento=login" \
  -d "usuario=TestAdministrador" \
  -d "rol=administrador" \
  -d "tipo_sesion=admin_override" \
  -d "negocio=Tienda de Prueba Central" \
  -d "hardware_id=TEST1234567890AB"
echo -e "\n"

sleep 1

# Test 4: Login - Modo Informe
echo "Test 4: Login - Modo Informe"
curl -X POST "$URL" \
  -d "tipo_evento=login" \
  -d "usuario=TestAdministrador" \
  -d "rol=administrador" \
  -d "tipo_sesion=modo_informe" \
  -d "negocio=Tienda de Prueba Central" \
  -d "hardware_id=TEST1234567890AB"
echo -e "\n"

sleep 1

# Test 5: Cierre de Turno
echo "Test 5: Cierre de Turno"
curl -X POST "$URL" \
  -d "tipo_evento=cierre_turno" \
  -d "usuario=Cajero1" \
  -d "rol=cajero" \
  -d "negocio=Tienda de Prueba Central" \
  -d "hardware_id=TEST1234567890AB"
echo -e "\n"

sleep 1

# Test 6: Salida de App
echo "Test 6: Salida de Aplicación"
curl -X POST "$URL" \
  -d "tipo_evento=salida_app" \
  -d "usuario=Cajero1" \
  -d "rol=cajero" \
  -d "negocio=Tienda de Prueba Central" \
  -d "hardware_id=TEST1234567890AB"
echo -e "\n"

sleep 1

# Test 7: Datos Mínimos (solo campos obligatorios)
echo "Test 7: Solo Campos Obligatorios"
curl -X POST "$URL" \
  -d "tipo_evento=login" \
  -d "usuario=UsuarioMinimo"
echo -e "\n"

sleep 1

# Test 8: Error - Tipo de evento inválido
echo "Test 8: Error - Tipo de Evento Inválido"
curl -X POST "$URL" \
  -d "tipo_evento=evento_invalido" \
  -d "usuario=TestUser"
echo -e "\n"

sleep 1

# Test 9: Error - Usuario faltante
echo "Test 9: Error - Usuario Faltante"
curl -X POST "$URL" \
  -d "tipo_evento=login"
echo -e "\n"

sleep 1

# Test 10: Error - Método GET (debe fallar)
echo "Test 10: Error - Método GET (debe fallar con 405)"
curl -X GET "$URL"
echo -e "\n"

echo ""
echo "=========================================="
echo "Pruebas Completadas"
echo "=========================================="
echo ""
echo "Verificar registros en MySQL con:"
echo "SELECT * FROM uso_punto_venta ORDER BY fecha_hora DESC LIMIT 10;"
