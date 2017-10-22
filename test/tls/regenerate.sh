#!/usr/bin/env bash

set -x
set -e

if [ $# -eq 0 ]; then
    $0 sha256
    $0 sha1
    $0 md5

    exit 0
fi

alg=$1

# Generate CA

if [ ! -f ca.key ]; then
    openssl genrsa -out ca.key 2048
fi

# Always use a SHA-1 CA to test that signature checks have no impact there
openssl req -key ca.key -new -x509 -sha1 -out ca.crt -extensions v3_ca -subj "/C=DE/ST=Internet/O=amphp/CN=amphp"
cat ca.crt ca.key > ca.pem

# Generate certificate for amphp.org

if [ ! -f amphp.org.key ]; then
    openssl genrsa -out amphp.org.key 2048
fi

openssl req -new -key amphp.org.key -out amphp.org.${alg}.csr -subj "/C=DE/ST=Germany/O=amphp/CN=amphp.org"
openssl x509 -req -CA ca.pem -CAcreateserial -days 365 -${alg} -in amphp.org.${alg}.csr -out amphp.org.${alg}.crt
cat amphp.org.${alg}.crt amphp.org.key > amphp.org.${alg}.pem
rm amphp.org.${alg}.csr ca.srl

# Generate certificate for www.amphp.org

if [ ! -f www.amphp.org.key ]; then
    openssl genrsa -out www.amphp.org.key 2048
fi

openssl req -new -key www.amphp.org.key -out www.amphp.org.${alg}.csr -subj "/C=DE/ST=Germany/O=amphp/CN=www.amphp.org"
openssl x509 -req -CA ca.pem -CAcreateserial -days 365 -${alg} -in www.amphp.org.${alg}.csr -out www.amphp.org.${alg}.crt
cat www.amphp.org.${alg}.crt www.amphp.org.key > www.amphp.org.${alg}.pem
rm www.amphp.org.${alg}.csr ca.srl
