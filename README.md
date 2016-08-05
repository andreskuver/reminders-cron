#REMINDERS CRON


## Getting up and running

1. Clone the repository: git clone https://github.com/andreskuver/reminders-cron.git
2. cd reminders-cron
3. Run php local server: php -S localhost:8080
4. Visit localhost:8080

## Running as Cron Job

1. Exec crontab -e
2. Write: 
  */15 * * * * <path-to-php>/php <path-to-here>/reminders-cron/index.php
3. This will be executed every 15 minutes.