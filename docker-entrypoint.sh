#!/bin/bash
# Start cron daemon in the background, then hand off to Apache.
cron
exec apache2-foreground
