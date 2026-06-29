# Deploying Surgical Devices ERP to AWS EC2

A single-instance Docker deployment: one EC2 box runs the React PWA (nginx),
the Laravel API, a queue worker, a scheduler and PostgreSQL. Good for pilot /
small-production. For larger scale, move Postgres to RDS and files to S3 (notes
at the end).

```
Browser ──▶ :80/:443 ──▶ frontend (nginx)
                              ├── serves the React PWA
                              └── proxies /api, /storage ──▶ backend (Laravel)
                                                                 ├── queue worker (emails)
                                                                 ├── scheduler (expiry/low-stock)
                                                                 └── PostgreSQL (internal only)
```

---

## 1. Launch the EC2 instance (AWS Console)

1. **EC2 → Launch instance.**
2. **Name:** `surgical-erp`.
3. **AMI:** Ubuntu Server 24.04 LTS (x86_64).
4. **Instance type:** `t3.small` (2 GB RAM) minimum; `t3.medium` (4 GB) is
   comfortable. *(t2.micro/1 GB can OOM during the frontend build — the
   bootstrap script adds swap to help, but 2 GB+ is recommended.)*
5. **Key pair:** create or choose one (you'll SSH with it).
6. **Network / Security group — add inbound rules:**
   | Type  | Port | Source            |
   | ----- | ---- | ----------------- |
   | SSH   | 22   | **My IP**         |
   | HTTP  | 80   | Anywhere (0.0.0.0/0) |
   | HTTPS | 443  | Anywhere (only if using a domain) |
7. **Storage:** 30 GB gp3.
8. Launch. Then **Elastic IP → Allocate → Associate** with the instance (so the
   public IP is stable). Note this IP.

---

## 2. Get the code onto the instance

From your Mac (replace IP and key path). This copies the project without the
heavy build artefacts:

```bash
rsync -avz --exclude node_modules --exclude vendor --exclude dist \
  --exclude '.git' --exclude 'backend/database/database.sqlite' \
  -e "ssh -i ~/path/to/key.pem" \
  /Users/tafsfolder/Documents/surgicaltool/ \
  ubuntu@<ELASTIC_IP>:/home/ubuntu/surgicaltool/
```

*(Alternative: push the repo to GitHub and `git clone` it on the instance.)*

---

## 3. Bootstrap the instance (once)

SSH in and install Docker + swap + firewall:

```bash
ssh -i ~/path/to/key.pem ubuntu@<ELASTIC_IP>
cd ~/surgicaltool
chmod +x deploy/*.sh
sudo ./deploy/aws-bootstrap.sh
exit          # log out/in so your user joins the docker group
```

---

## 4. Deploy

SSH back in and run the deploy script. The first run generates a secure
`.env` (random `APP_KEY` + DB password, auto-detected public IP) and `--seed`
creates the roles, permissions and demo logins:

```bash
ssh -i ~/path/to/key.pem ubuntu@<ELASTIC_IP>
cd ~/surgicaltool
./deploy/deploy.sh --seed
```

Open **http://&lt;ELASTIC_IP&gt;** and log in:

- `admin@surgical.test` / `password`  (also `super@`, `mike@`, …)

> **Change/disable the demo accounts** in the Users screen before real use, and
> create your own Super Admin.

Subsequent deploys (after `rsync`-ing new code) are just:

```bash
./deploy/deploy.sh
```

---

## 5. (Optional) HTTPS with a domain

1. Point a DNS **A record** (e.g. `erp.yourcompany.com`) at the Elastic IP.
2. Add to `~/surgicaltool/.env`:
   ```
   DOMAIN=erp.yourcompany.com
   ACME_EMAIL=you@yourcompany.com
   APP_URL=https://erp.yourcompany.com
   ```
3. Bring it up with the TLS overlay (Caddy auto-provisions Let's Encrypt):
   ```bash
   docker compose -f docker-compose.yml \
     -f deploy/docker-compose.prod.yml \
     -f deploy/docker-compose.tls.yml up -d --build
   ```

Make sure port 443 is open in the security group.

---

## 6. Email & file storage in production

Edit `~/surgicaltool/.env` then re-run `./deploy/deploy.sh`:

- **Email (e.g. AWS SES SMTP):**
  ```
  MAIL_MAILER=smtp
  MAIL_HOST=email-smtp.eu-west-1.amazonaws.com
  MAIL_PORT=587
  MAIL_USERNAME=...        # SES SMTP credentials
  MAIL_PASSWORD=...
  MAIL_FROM_ADDRESS=no-reply@yourcompany.com
  ```
- **Documents on S3** (instead of the local volume):
  ```
  FILESYSTEM_DISK=s3
  AWS_ACCESS_KEY_ID=...
  AWS_SECRET_ACCESS_KEY=...
  AWS_DEFAULT_REGION=eu-west-1
  AWS_BUCKET=surgical-erp-documents
  ```

---

## 7. Day-2 operations

```bash
cd ~/surgicaltool
DC="docker compose -f docker-compose.yml -f deploy/docker-compose.prod.yml"

$DC ps                       # status
$DC logs -f backend          # API logs (also: queue, scheduler, frontend)
$DC restart backend
$DC down                     # stop everything (data volumes persist)

# Database backup / restore
$DC exec -T db pg_dump -U surgical surgical_erp > backup-$(date +%F).sql
cat backup.sql | $DC exec -T db psql -U surgical -d surgical_erp

# Make a user a Super Admin from the CLI
$DC exec backend php artisan tinker --execute \
  "App\Models\User::where('email','you@co.com')->first()->syncRoles(['super_admin']);"
```

---

## Production hardening checklist

- [ ] Replace demo accounts; set strong passwords.
- [ ] Restrict SSH (port 22) to your IP only.
- [ ] Use a domain + HTTPS (section 5); set `SESSION_SECURE_COOKIE=true`.
- [ ] Move the DB to **RDS PostgreSQL** and files to **S3** for durability.
- [ ] Schedule automated `pg_dump` backups (or RDS snapshots).
- [ ] Put the instance behind an ALB / CloudFront if you need scale or WAF.
- [ ] Monitor with CloudWatch; ship container logs.
```
