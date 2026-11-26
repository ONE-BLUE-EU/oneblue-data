#!/bin/sh
set -e

APP=/var/www/html

# Writable dirs. Named volumes are created root-owned on first start.
for d in "$APP/docroot/sites/default/files" "$APP/private"; do
  mkdir -p "$d"
  if [ "$(stat -c %U "$d")" != "www-data" ]; then
    chown -R www-data:www-data "$d"
  fi
done

# Outgoing mail (msmtp). Without SMTP_HOST, mail is accepted and dropped.
if [ -n "${SMTP_HOST:-}" ]; then
  {
    echo "defaults"
    echo "logfile -"
    echo "account default"
    echo "host ${SMTP_HOST}"
    echo "port ${SMTP_PORT:-587}"
    echo "from ${SMTP_FROM:-noreply@localhost}"
    if [ -n "${SMTP_USER:-}" ]; then
      echo "auth on"
      echo "user ${SMTP_USER}"
      echo "password ${SMTP_PASSWORD:-}"
    else
      echo "auth off"
    fi
    echo "tls ${SMTP_TLS:-on}"
    echo "tls_starttls ${SMTP_STARTTLS:-on}"
  } > /etc/msmtprc
else
  printf 'account default\nhost localhost\nport 25\nauth off\n' > /etc/msmtprc
  sed -i 's#^sendmail_path.*#sendmail_path = "/bin/true"#' "$PHP_INI_DIR/conf.d/10-app.ini"
fi
chmod 644 /etc/msmtprc

exec "$@"
