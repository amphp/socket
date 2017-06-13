#!/usr/bin/env bash
openssl genrsa -out server.key 2048

openssl req -new -key server.key -out amphp.org.csr -subj "/C=DE/ST=Germany/O=amphp/CN=amphp.org"
openssl x509 -req -days 365 -in amphp.org.csr -signkey server.key -out amphp.org.crt
cat amphp.org.crt server.key > amphp.org.pem
rm amphp.org.csr

openssl req -new -key server.key -out www.amphp.org.csr -subj "/C=DE/ST=Germany/O=amphp/CN=www.amphp.org"
openssl x509 -req -days 365 -in www.amphp.org.csr -signkey server.key -out www.amphp.org.crt
cat www.amphp.org.crt server.key > www.amphp.org.pem
rm www.amphp.org.csr
