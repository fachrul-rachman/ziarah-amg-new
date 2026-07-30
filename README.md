# Al Azhar Memorial Garden Pilgrimage Booking

## Local Docker setup

```powershell
Copy-Item .env.example .env
docker compose build
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose up -d
```

Open `http://localhost:8000`.

The application, queue worker, and scheduler use the same application image
with different commands. PostgreSQL stores application data and provides the
isolated `ziarah_amg_testing` database used by Pest.

Create the first admin with an interactive password prompt:

```powershell
docker compose run --rm app php artisan admin:create admin@example.com --name=Administrator
```

Then sign in at `http://localhost:8000/admin/login`.

Customer email uses Brevo's SMTP relay. Complete these values in `.env`:

```dotenv
MAIL_USERNAME=your-brevo-smtp-login
MAIL_PASSWORD=your-brevo-smtp-key
MAIL_FROM_ADDRESS=your-verified-sender@example.com
```

Use the SMTP login and SMTP key from Brevo, not the Brevo account password or
an API key. The sender address must be verified in Brevo. Apply `.env` changes
to the long-running application processes with:

```powershell
docker compose up -d --force-recreate app web queue scheduler
```

No additional mail service is needed by the application.

## Checks

```powershell
docker compose run --rm test
docker compose run --rm app npm run build
docker compose exec app php artisan about
docker compose ps
```

Always run Pest through the `test` service. It uses `ziarah_amg_testing`, and
the test bootstrap refuses to run against any database without the `_testing`
suffix.

Rebuild after source or dependency changes:

```powershell
docker compose up -d --build
```

Stop the stack without deleting PostgreSQL data:

```powershell
docker compose down
```

Production configuration, deployment, health monitoring, backup, restore, and
rollback procedures are documented in
[`docs/PRODUCTION_RUNBOOK.md`](docs/PRODUCTION_RUNBOOK.md).

Tutorial deployment lengkap untuk VPS Hostinger Ubuntu, Nginx, Certbot, queue,
dan scheduler tersedia di
[`docs/DEPLOY_TO_VPS.md`](docs/DEPLOY_TO_VPS.md).
