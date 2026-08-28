#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="${1:-telegram-bot-shop.it.id.vn}"
SITE="${2:-/www/wwwroot/$DOMAIN}"
CONF="/www/server/panel/vhost/apache/$DOMAIN.conf"

test -d "$SITE/public"
test -f "$CONF"

if [[ ! -e "$SITE/public/.well-known" ]]; then
    ln -s ../.well-known "$SITE/public/.well-known"
fi

cp -a "$CONF" "$CONF.bak-$(date +%Y%m%d%H%M%S)"
sed -i \
    -e "s#DocumentRoot \"$SITE/\"#DocumentRoot \"$SITE/public/\"#g" \
    -e "s#DocumentRoot \"$SITE\"#DocumentRoot \"$SITE/public\"#g" \
    -e "s#<Directory \"$SITE/\">#<Directory \"$SITE/public/\">#g" \
    -e "s#<Directory \"$SITE\">#<Directory \"$SITE/public\">#g" \
    "$CONF"

/www/server/apache/bin/apachectl -t
/etc/init.d/httpd reload

echo "APACHE_CONFIGURED: $DOMAIN -> $SITE/public"
