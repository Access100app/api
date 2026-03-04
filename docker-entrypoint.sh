#!/bin/bash
# Export Docker environment variables so cron jobs can access them.
# Cron runs in a minimal environment without Docker's env vars, so we
# dump the relevant vars into a sourceable file that each job loads.
printenv | grep -E '^(DB_|API_|GMAIL_|TWILIO_|CORS_|CLAUDE_)' | sed 's/=\(.*\)/="\1"/' | sed 's/^/export /' > /etc/cron-env.sh
chown www-data:www-data /etc/cron-env.sh
chmod 600 /etc/cron-env.sh

# Ensure log files are owned by www-data (cron jobs run as www-data).
chown www-data:www-data /var/log/access100-*.log

# Start cron daemon in the background, then hand off to Apache.
cron
exec apache2-foreground
