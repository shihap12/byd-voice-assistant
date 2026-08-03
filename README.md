# BYD AI Voice Assistant — Backend Architecture

**PHP 8.3+ | No Framework | PSR-4 | Redis | MySQL | Vapi**

---

## Project Structure

```
byd-voice-assistant/
├── app/
│   ├── Controllers/
│   │   ├── Router.php              # Simple URI dispatcher
│   │   ├── VapiWebhookController.php  # Vapi events handler
│   │   └── UploadController.php    # PDF upload endpoint
│   ├── Models/
│   │   ├── Database.php            # Singleton PDO
│   │   ├── RedisClient.php         # Singleton Predis wrapper
│   │   └── CarModel.php            # Car data access layer
│   ├── Services/
│   │   ├── AuthService.php         # JWT generation & validation
│   │   └── PdfService.php          # PDF text extraction + caching
│   ├── Security/
│   │   └── Security.php            # CSRF, XSS, Rate Limiting
│   ├── Queue/
│   │   ├── Worker.php              # Daemon loop
│   │   ├── Contracts/
│   │   │   └── JobInterface.php
│   │   └── Jobs/
│   │       ├── PdfProcessingJob.php  # Extract specs from PDF
│   │       ├── SpecSyncJob.php       # Sync from BYD API
│   │       └── NotificationJob.php   # Async notifications
│   └── helpers.php                 # Global utility functions
│
├── config/
│   └── supervisord.conf            # Worker process management
│
├── database/
│   ├── migrate.php                 # Migration runner CLI
│   ├── migrations/
│   │   └── 001_create_tables.sql   # Schema + Composite Indexes
│   └── seeds/
│       └── 001_sample_cars.sql     # Sample BYD data
│
├── public/
│   ├── index.php                   # Entry point + routing
│   └── .htaccess                   # Apache rewrite + security headers
│
├── workers/
│   └── queue_worker.php            # Worker daemon entry point
│
├── storage/
│   └── pdf_cache/                  # Extracted text disk cache
│
├── logs/                           # app.log, worker.log, etc.
├── composer.json
└── .env.example
```

---

## Quick Start

```bash
# 1. Install dependencies
composer install

# 2. Configure environment
cp .env.example .env
# Edit .env with your DB/Redis credentials

# 3. Run migrations
php database/migrate.php

# 4. Start a worker (development)
php workers/queue_worker.php pdf_processing

# 5. Start PHP dev server
composer serve
```

---

## Vapi Webhook Flow

```
Vapi Call Start
     │
     ▼
POST /api/vapi/webhook
     │
     ├─ assistant-request → Build system prompt, return assistant config
     │                       Store session context in Redis (30 min TTL)
     │
     ├─ function-call → "get_car_specifications"
     │                   ├─ Check Redis cache
     │                   ├─ Query MySQL (cars + specifications)
     │                   └─ Return JSON result to Vapi AI
     │
     └─ end-of-call → Log to DB, delete Redis context
```

---

## Queue System

```
[Upload PDF] → UploadController → redis.pushJob("pdf_processing", payload)
                                          │
                                          ▼
                               Worker Daemon (BLPOP - blocking)
                                          │
                                          ▼
                               PdfProcessingJob.handle()
                                ├─ smalot/pdfparser → extract text
                                ├─ Parse spec key-values
                                ├─ DB: upsert specifications
                                └─ Redis: cache full text (7 days)
```

**Retry Logic:** Exponential backoff — 5s → 25s → 125s  
**Dead Letter Queue:** `byd:queue:pdf_processing:failed`

---

## Security Layers

| Layer         | Implementation                          |
|---------------|----------------------------------------|
| CSRF          | HMAC-SHA256 token, one-time use, Redis  |
| XSS           | `htmlspecialchars` + `strip_tags`       |
| Rate Limiting | Token Bucket via Redis (60 req/min)     |
| JWT           | `firebase/php-jwt` + blacklist in Redis |
| Vapi Webhook  | HMAC-SHA256 signature validation        |
| SQL Injection | PDO prepared statements only            |

---

## Database Indexes Strategy

```sql
-- specifications: most queries = car_id + group
INDEX idx_car_group (car_id, spec_group)

-- logs: analytics = intent + date
INDEX idx_intent_date (intent, created_at DESC)

-- cars: search = model_name + year
INDEX idx_model_year (model_name, year)
```

---

## Production Deployment

```bash
# Workers managed by Supervisord
sudo cp config/supervisord.conf /etc/supervisor/conf.d/byd-workers.conf
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status

# Verify workers running
sudo supervisorctl status byd-pdf-worker:*
```
