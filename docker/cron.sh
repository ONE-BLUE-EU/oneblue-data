#!/bin/sh
# Runs Drupal cron (which also processes DKAN's harvest/datastore/metastore
# queues) every CRON_INTERVAL seconds as www-data. Started by the "cron" service.
INTERVAL="${CRON_INTERVAL:-60}"
cd /var/www/html || exit 1
echo "cron: running drush cron every ${INTERVAL}s"
while :; do
  if runuser -u www-data -- env HOME=/tmp drush status --field=bootstrap 2>/dev/null | grep -q Successful; then
    runuser -u www-data -- env HOME=/tmp drush cron -q || echo "cron: drush cron failed"
  fi
  sleep "$INTERVAL"
done
