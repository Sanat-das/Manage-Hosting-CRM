# Emails queue on shared hosting (no systemd/supervisor)

Shared hosting usually cannot run a long-lived `queue:work` daemon. Use cron instead.

## What was added

`routes/console.php` now schedules:

```
queue:work --queue=emails --sleep=3 --tries=3 --stop-when-empty --max-time=50
  ->everyMinute()->withoutOverlapping()
```

It only needs the normal Laravel cron:

```
* * * * * /usr/bin/php /path/to/app/artisan schedule:run >> /dev/null 2>&1
```

That one cron powers all scheduled tasks, including the emails worker above. The worker boots, drains the `emails` queue for up to 50s, then exits. `withoutOverlapping` prevents two runs colliding.

## cPanel setup

1. cPanel -> Cron Jobs -> Add New
2. Common Settings: Every Minute (`* * * * *`)
3. Command:
```
php /home/YOUR_USER/managehosting/artisan schedule:run >> /dev/null 2>&1
```
Replace `/home/YOUR_USER/managehosting` with the real path (check File Manager). PHP binary may be `php`, `php8.4`, or `/opt/alt/php84/usr/bin/php` — use your host's selector.

Alternative direct queue cron (if you prefer not to use `schedule:run`):

```
* * * * * php /home/YOUR_USER/managehosting/artisan queue:work --queue=emails --sleep=3 --tries=3 --stop-when-empty --max-time=50 >> /dev/null 2>&1
```

## Verify

```bash
php artisan schedule:list
php artisan queue:failed   # should be empty
# after creating an order/invoice:
php artisan queue:work --queue=emails --stop-when-empty  # manual drain test
```

## Notes

- Systemd unit `deploy/systemd/managehosting-queue-emails.service` remains for VPS/dedicated servers — cron is just the shared-hosting fallback. They can coexist; only one will actually claim jobs due to `withoutOverlapping`.
- If your host only allows 5-minute cron, change `everyMinute()` to `everyFiveMinutes()` — delivery will just be delayed up to 5 min.
- `QUEUE_CONNECTION=database` is kept. Switching to `sync` would also work (no worker needed) but makes web requests slower and hides failures, so cron + database queue is preferred.
