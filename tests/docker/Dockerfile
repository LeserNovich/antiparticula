# PHP 8.3 + pdo_mysql + curl para las pruebas del panel móvil (tests/correr_api.sh).
# Se parte de alpine y se instala con apk: la imagen oficial php:*-cli exige una descarga
# grande del registro de Docker que en algunas redes se queda colgada.
FROM alpine:latest
RUN apk add --no-cache php83-cli php83-pdo_mysql php83-curl php83-mbstring php83-openssl \
 && ln -sf /usr/bin/php83 /usr/bin/php
