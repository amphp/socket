#!/usr/bin/env bash
openssl genrsa -out amphp.org.key 2048
openssl genrsa -out www.amphp.org.key 2048

openssl req -new -key amphp.org.key -out amphp.org.csr -subj "/C=DE/ST=Germany/O=amphp/CN=amphp.org"
openssl x509 -req -days 365 -in amphp.org.csr -signkey amphp.org.key -out amphp.org.crt
cat amphp.org.crt amphp.org.key > amphp.org.pem
rm amphp.org.csr

openssl req -new -key www.amphp.org.key -out www.amphp.org.csr -subj "/C=DE/ST=Germany/O=amphp/CN=www.amphp.org"
openssl x509 -req -days 365 -in www.amphp.org.csr -signkey www.amphp.org.key -out www.amphp.org.crt
cat www.amphp.org.crt www.amphp.org.key > www.amphp.org.pem
rm www.amphp.org.csr
